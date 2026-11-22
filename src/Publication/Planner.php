<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Shipwell\Afterburner\Configuration\Agent;
use Shipwell\Afterburner\Configuration\PublishedState;
use Shipwell\Afterburner\Configuration\Settings;
use Shipwell\Afterburner\Installation\Selection;
use Shipwell\Afterburner\Rules\RuleFile;
use Shipwell\Afterburner\Rules\RuleIndex;
use Shipwell\Afterburner\Rules\ScopedRules;

/**
 * Builds a validated publication plan without writing anything.
 *
 * Every selected source is read, rendered, and validated, and every output
 * conflict is detected, before a plan is returned. A PublicationFailed
 * exception therefore always means previous output is untouched.
 */
class Planner
{
    /** @var array<int, string> */
    protected array $warnings = [];

    protected Adoption $adoption;

    public function __construct(
        protected readonly string $basePath,
        protected readonly Renderer $renderer,
    ) {
        //
    }

    /**
     * @throws PublicationFailed
     */
    public function plan(Settings $settings, Selection $selection, SourceCatalog $catalog, ?Adoption $adoption = null): Plan
    {
        $this->warnings = [];
        $this->adoption = $adoption ?? new Adoption;

        foreach ($catalog->ignoredSkillDirectories() as $directory) {
            $this->warnings[] = 'Skipped '.$this->relative($directory).': it has no SKILL.md entry point.';
        }

        $skills = $this->resolveSkills($catalog->skills());
        $this->renderer->useSkills(array_map(fn (PreparedSkill $skill): string => $skill->description, $skills));

        [$guidelineText, $guidelineKeys, $scopedBlocks] = $this->composeGuidelines($catalog->guidelines(), $settings->scopedGuidelines);

        $documents = $guidelineText === '' ? [] : $this->documentsFor($selection->agents);
        $destinations = $this->skillDirectoriesFor($selection->agents);

        $previous = $this->withAdoption($settings->published);

        $plannedDocuments = [];

        foreach ($documents as $document) {
            $plannedDocuments[$document] = ManagedBlock::put($this->readDocument($document), $guidelineText, $document);
        }

        $documentRemovals = [];

        foreach ($previous->guidelines as $document) {
            if (in_array($document, $documents, true)) {
                continue;
            }

            if (! in_array($document, [...self::knownDocuments(), ...$this->adoption->documents], true)) {
                $this->warnings[] = "Stopped tracking {$document}: it is not an Afterburner guideline document, so it was left unchanged.";

                continue;
            }

            if (! is_file($this->path($document))) {
                continue;
            }

            $remaining = ManagedBlock::remove($this->readDocument($document), $document);
            $documentRemovals[$document] = trim($remaining) === '' ? null : $remaining;
        }

        $skillDestinations = [];
        $skillRemovals = [];
        $names = array_keys($skills);

        foreach ($destinations as $directory) {
            $tracked = $previous->skillsIn($directory);

            foreach ($names as $name) {
                $target = $directory.'/'.$name;

                if (! in_array($name, $tracked, true) && (file_exists($this->path($target)) || is_link($this->path($target)))) {
                    throw PublicationFailed::target(
                        $target,
                        "an existing skill that Afterburner did not publish has the same name as the selected skill from {$this->relative($skills[$name]->source->directory)}. Rename or remove one of them; Afterburner will not overwrite it",
                    );
                }
            }

            $skillDestinations[$directory] = $names;
            $skillRemovals[$directory] = array_values(array_diff($tracked, $names));
        }

        foreach ($previous->skills as $directory => $tracked) {
            if (isset($skillDestinations[$directory])) {
                continue;
            }

            if (! in_array($directory, [...self::knownSkillDirectories(), ...array_keys($this->adoption->skills)], true)) {
                $this->warnings[] = "Stopped tracking skills in {$directory}: it is not an Afterburner skill directory, so it was left unchanged.";

                continue;
            }

            $skillRemovals[$directory] = $tracked;
        }

        $rules = $settings->scopedGuidelines ? ScopedRules::compose($scopedBlocks) : [];
        [$ruleRemovals, $ruleIndexChanges, $ruleIndex] = $this->planRules($rules, $previous->rules);

        return new Plan(
            documents: $plannedDocuments,
            documentRemovals: $documentRemovals,
            skills: $skills,
            skillDestinations: $skillDestinations,
            skillRemovals: array_filter($skillRemovals),
            published: new PublishedState(
                guidelines: $documents,
                skills: $skillDestinations,
                rules: array_keys($rules),
            ),
            guidelineKeys: $guidelineKeys,
            rules: $rules,
            ruleRemovals: $ruleRemovals,
            ruleIndexChanges: $ruleIndexChanges,
            ruleIndex: $ruleIndex,
            previous: $previous,
        );
    }

    /**
     * The previous publication state plus any adopted Boost output.
     */
    protected function withAdoption(PublishedState $published): PublishedState
    {
        $skills = $published->skills;

        foreach ($this->adoption->skills as $directory => $names) {
            $skills[$directory] = array_values(array_unique([...($skills[$directory] ?? []), ...$names]));
        }

        return new PublishedState(
            guidelines: array_values(array_unique([...$published->guidelines, ...$this->adoption->documents])),
            skills: $skills,
            rules: array_values(array_unique([...$published->rules, ...$this->adoption->rules])),
        );
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Render and compose the selected guidelines. With extraction enabled,
     * recorded `@scoped` blocks are removed from the standing text and
     * returned for the managed rule files.
     *
     * @param  array<string, GuidelineSource>  $sources
     * @return array{0: string, 1: array<int, string>, 2: array<int, array{paths: array<int, string>, body: string}>}
     *
     * @throws PublicationFailed
     */
    protected function composeGuidelines(array $sources, bool $extractScoped): array
    {
        $sections = [];
        $scoped = [];

        foreach ($sources as $key => $source) {
            $rendered = $this->renderer->render($source->path);
            $content = trim($extractScoped ? $rendered->withoutScoped() : $rendered->content);

            if ($extractScoped) {
                array_push($scoped, ...$rendered->scoped);
            }

            if ($content === '') {
                continue;
            }

            if (ManagedBlock::containsDelimiter($content)) {
                throw PublicationFailed::source($this->relative($source->path), 'the guideline contains an Afterburner delimiter line, which would make the managed section ambiguous');
            }

            $sections[$key] = $content;
        }

        $composed = trim(implode("\n\n", array_map(
            fn (string $key, string $content): string => "\n=== {$key} rules ===\n\n".$content,
            array_keys($sections),
            $sections,
        )));

        return [Markdown::format($composed), array_keys($sections), $scoped];
    }

    /**
     * Plan generated rule changes and the resulting index.
     *
     * @param  array<string, string>  $rules  generated rule file => contents
     * @param  array<int, string>  $tracked  previously generated rule files
     * @return array{0: array<int, string>, 1: bool, 2: string|null}
     *
     * @throws PublicationFailed
     */
    protected function planRules(array $rules, array $tracked): array
    {
        foreach (array_keys($rules) as $relative) {
            if (! in_array($relative, $tracked, true) && file_exists($this->path($relative))) {
                throw PublicationFailed::target($relative, 'an existing rule file that Afterburner did not generate is in the way of an extracted scoped rule. Move or rename it; Afterburner will not overwrite it');
            }
        }

        $removals = [];

        foreach ($tracked as $relative) {
            if (isset($rules[$relative])) {
                continue;
            }

            if (! str_starts_with($relative, RuleIndex::MANAGED_DIRECTORY.'/')) {
                $this->warnings[] = "Stopped tracking {$relative}: it is not a generated rule file, so it was left unchanged.";

                continue;
            }

            $removals[] = $relative;
        }

        $index = new RuleIndex($this->basePath);
        $changed = $removals !== [] || ($rules !== [] && ! is_file($index->path(RuleIndex::INDEX)));

        foreach ($rules as $relative => $contents) {
            $changed = $changed || @file_get_contents($this->path($relative)) !== $contents;
        }

        if (! $changed) {
            return [$removals, false, null];
        }

        $files = $index->files([...$rules, ...array_fill_keys($removals, null)]);
        $conflicted = array_filter($files, fn (RuleFile $file): bool => $file->conflicted);

        if ($conflicted !== []) {
            throw PublicationFailed::target(
                RuleIndex::INDEX,
                'the index must be regenerated but these rule files contain unresolved merge conflicts: '.implode(', ', array_map(fn (RuleFile $file): string => $file->relative, $conflicted)),
            );
        }

        return [$removals, true, $files === [] ? null : RuleIndex::render($files)];
    }

    /**
     * Establish every candidate's identity, apply precedence, then prepare only the winners.
     *
     * @param  array<int, SkillSource>  $sources
     * @return array<string, PreparedSkill>
     *
     * @throws PublicationFailed
     */
    protected function resolveSkills(array $sources): array
    {
        $winners = [];
        $explicit = [];

        foreach ($sources as $source) {
            $entry = $this->renderer->render($source->entryPath())->content;
            $metadata = Frontmatter::skill($entry, $this->relative($source->entryPath()));
            $name = $metadata['name'];

            if ($this->isExplicitApplicationSkill($source)) {
                if (isset($explicit[$name])) {
                    throw PublicationFailed::source(
                        $this->relative($source->directory),
                        "the skill name \"{$name}\" is also used by {$this->relative($explicit[$name])}; application skill names must be unique",
                    );
                }

                $explicit[$name] = $source->directory;
            }

            unset($winners[$name]);
            $winners[$name] = [$source, $entry, $metadata];
        }

        $skills = [];

        foreach ($winners as $name => [$source, $entry, $metadata]) {
            $skills[$name] = $this->prepareSkill($source, $entry, $metadata);
        }

        return $skills;
    }

    protected function isExplicitApplicationSkill(SkillSource $source): bool
    {
        return $source->origin === Origin::Application
            && dirname($source->directory) === Paths::join($this->basePath, '.ai', 'skills');
    }

    /**
     * Collect a winning skill's published files: the rendered entry point as
     * SKILL.md, rendered Blade supporting files as `.md`, and other files as-is.
     *
     * @param  array{name: string, description: string}  $metadata
     *
     * @throws PublicationFailed
     */
    protected function prepareSkill(SkillSource $source, string $entry, array $metadata): PreparedSkill
    {
        $files = ['SKILL.md' => ['contents' => $source->isBlade() ? self::formatRendered($entry) : $entry, 'mode' => null]];
        $origins = ['SKILL.md' => $source->entry];

        foreach ($this->skillFiles($source->directory) as $relative => $path) {
            if ($relative === 'SKILL.md' || $relative === 'SKILL.blade.php') {
                continue;
            }

            if (! Paths::isContained($relative)) {
                throw PublicationFailed::source($this->relative($path), 'the supporting file would be written outside the skill folder');
            }

            $target = str_ends_with($relative, '.blade.php') ? substr($relative, 0, -strlen('.blade.php')).'.md' : $relative;

            if (isset($origins[$target])) {
                throw PublicationFailed::source(
                    $this->relative($path),
                    "it would publish as {$target}, which {$origins[$target]} also publishes; rename one of them",
                );
            }

            $origins[$target] = $relative;
            $files[$target] = $this->supportingFile($relative, $path);
        }

        ksort($files, SORT_STRING);

        return new PreparedSkill($metadata['name'], $metadata['description'], $source, $files);
    }

    /**
     * @return array{contents: string, mode: int|null}
     *
     * @throws PublicationFailed
     */
    protected function supportingFile(string $relative, string $path): array
    {
        if (str_ends_with($relative, '.blade.php')) {
            return ['contents' => self::formatRendered($this->renderer->render($path)->content), 'mode' => null];
        }

        $contents = is_readable($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            throw PublicationFailed::source($this->relative($path), 'the supporting file is not readable');
        }

        return ['contents' => $contents, 'mode' => @fileperms($path) ?: null];
    }

    protected static function formatRendered(string $content): string
    {
        return rtrim(Markdown::format(trim($content)))."\n";
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    protected function skillFiles(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1))] = $file->getPathname();
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  array<int, Agent>  $agents
     * @return array<int, string>
     */
    protected function documentsFor(array $agents): array
    {
        return array_values(array_unique(array_map(fn (Agent $agent): string => $agent->guidelineDocument(), $agents)));
    }

    /**
     * @param  array<int, Agent>  $agents
     * @return array<int, string>
     */
    protected function skillDirectoriesFor(array $agents): array
    {
        return array_values(array_unique(array_map(fn (Agent $agent): string => $agent->skillsDirectory(), $agents)));
    }

    /**
     * @return array<int, string>
     */
    public static function knownDocuments(): array
    {
        return array_values(array_unique(array_map(fn (Agent $agent): string => $agent->guidelineDocument(), Agent::cases())));
    }

    /**
     * @return array<int, string>
     */
    public static function knownSkillDirectories(): array
    {
        return array_values(array_unique(array_map(fn (Agent $agent): string => $agent->skillsDirectory(), Agent::cases())));
    }

    /**
     * @throws PublicationFailed
     */
    protected function readDocument(string $document): string
    {
        $path = $this->path($document);

        if (! file_exists($path)) {
            return '';
        }

        $contents = is_file($path) ? @file_get_contents($path) : false;

        if ($contents === false) {
            throw PublicationFailed::target($document, 'the guideline document exists but is not a readable file');
        }

        return in_array($document, $this->adoption->documents, true)
            ? Adoption::convertDocument($contents, $document)
            : $contents;
    }

    protected function path(string $relative): string
    {
        return Paths::join($this->basePath, $relative);
    }

    protected function relative(string $path): string
    {
        return Paths::relative($this->basePath, $path);
    }
}
