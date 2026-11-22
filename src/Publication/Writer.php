<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Shipwell\Afterburner\Configuration\PublishedState;
use Shipwell\Afterburner\Rules\RuleIndex;

/**
 * Applies a validated plan to the filesystem.
 *
 * Ownership is tracked as work proceeds, so when a filesystem operation
 * fails the caller can still record exactly which outputs Afterburner owns.
 */
final class Writer
{
    /** @var array<int, string> */
    private array $documents = [];

    /** @var array<string, array<int, string>> */
    private array $skills = [];

    /** @var array<int, string> */
    private array $rules = [];

    /** @var array<int, string> */
    private array $completed = [];

    public function __construct(private readonly string $basePath)
    {
        //
    }

    /**
     * @throws WriteFailed
     */
    public function apply(Plan $plan, PublishedState $previous): PublishedState
    {
        $this->documents = $previous->guidelines;
        $this->skills = $previous->skills;
        $this->rules = $previous->rules;
        $this->completed = [];

        foreach ($plan->skillRemovals as $directory => $names) {
            foreach ($names as $name) {
                $this->removeSkill($directory, $name);
            }

            $this->removeEmptySkillDirectory($directory);
        }

        foreach ($plan->skillDestinations as $directory => $names) {
            foreach ($names as $name) {
                $this->writeSkill($directory, $plan->skills[$name]);
            }
        }

        foreach ($plan->documents as $document => $contents) {
            $this->writeDocument($document, $contents);
        }

        foreach ($plan->documentRemovals as $document => $remaining) {
            $this->removeBlock($document, $remaining);
        }

        foreach ($plan->rules as $relative => $contents) {
            $this->rules[] = $relative;
            $this->atomicWrite($relative, $contents);
            $this->completed[] = "Wrote scoped rule {$relative}";
        }

        foreach ($plan->ruleRemovals as $relative) {
            if (is_file($this->path($relative)) && ! @unlink($this->path($relative))) {
                throw WriteFailed::operation('remove', $relative);
            }

            $this->rules = array_values(array_diff($this->rules, [$relative]));
            $this->completed[] = "Removed scoped rule {$relative}";
        }

        if ($plan->ruleIndexChanges) {
            $this->writeRuleIndex($plan->ruleIndex);
        }

        return $plan->published;
    }

    /**
     * The outputs Afterburner owns at this point, including any partially applied work.
     */
    public function owned(PublishedState $previous): PublishedState
    {
        return new PublishedState(
            guidelines: array_values(array_unique($this->documents)),
            skills: array_map(fn (array $names): array => array_values(array_unique($names)), $this->skills),
            rules: array_values(array_unique($this->rules)),
        );
    }

    /**
     * Human-readable descriptions of completed operations.
     *
     * @return array<int, string>
     */
    public function completed(): array
    {
        return $this->completed;
    }

    private function writeSkill(string $directory, PreparedSkill $skill): void
    {
        $target = $this->path($directory.'/'.$skill->name);
        $temporary = $this->path($directory.'/.'.$skill->name.'.afterburner-'.bin2hex(random_bytes(4)));

        $this->own($directory, $skill->name);

        try {
            foreach ($skill->files as $relative => $file) {
                $path = $temporary.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

                $this->ensureDirectory(dirname($path));

                if (@file_put_contents($path, $file['contents']) === false) {
                    throw WriteFailed::operation('write', $this->relative($path));
                }

                if ($file['mode'] !== null) {
                    @chmod($path, $file['mode'] & 0777);
                }
            }

            if (file_exists($target) || is_link($target)) {
                $this->delete($target);
            }

            if (! @rename($temporary, $target)) {
                throw WriteFailed::operation('publish the skill', $directory.'/'.$skill->name);
            }
        } finally {
            if (file_exists($temporary)) {
                $this->delete($temporary);
            }
        }

        $this->completed[] = "Published skill {$directory}/{$skill->name}";
    }

    private function removeSkill(string $directory, string $name): void
    {
        $target = $this->path($directory.'/'.$name);

        if (file_exists($target) || is_link($target)) {
            $this->delete($target);
        }

        $this->skills[$directory] = array_values(array_diff($this->skills[$directory] ?? [], [$name]));

        if ($this->skills[$directory] === []) {
            unset($this->skills[$directory]);
        }

        $this->completed[] = "Removed skill {$directory}/{$name}";
    }

    /**
     * Remove a skill directory left empty by cleanup, and its parent if that is now empty too.
     */
    private function removeEmptySkillDirectory(string $directory): void
    {
        $path = $this->path($directory);

        foreach ([$path, dirname($path)] as $candidate) {
            if ($candidate === $this->basePath || ! is_dir($candidate) || is_link($candidate)) {
                return;
            }

            if ((new FilesystemIterator($candidate))->valid()) {
                return;
            }

            @rmdir($candidate);
        }
    }

    private function writeRuleIndex(?string $contents): void
    {
        $managed = $this->path(RuleIndex::MANAGED_DIRECTORY);

        if (is_dir($managed) && ! (new FilesystemIterator($managed))->valid()) {
            @rmdir($managed);
        }

        if ($contents !== null) {
            $this->atomicWrite(RuleIndex::INDEX, $contents);
            $this->completed[] = 'Regenerated '.RuleIndex::INDEX;

            return;
        }

        $index = $this->path(RuleIndex::INDEX);

        if (is_file($index) && ! @unlink($index)) {
            throw WriteFailed::operation('remove', RuleIndex::INDEX);
        }

        $directory = $this->path(RuleIndex::DIRECTORY);

        if (is_dir($directory) && ! (new FilesystemIterator($directory))->valid()) {
            @rmdir($directory);
        }

        $this->completed[] = 'Removed '.RuleIndex::INDEX;
    }

    private function writeDocument(string $document, string $contents): void
    {
        $this->atomicWrite($document, $contents);

        $this->documents[] = $document;
        $this->completed[] = "Updated guidelines in {$document}";
    }

    private function removeBlock(string $document, ?string $remaining): void
    {
        $path = $this->path($document);

        if ($remaining === null) {
            if (is_file($path) && ! @unlink($path)) {
                throw WriteFailed::operation('remove', $document);
            }
        } else {
            $this->atomicWrite($document, $remaining);
        }

        $this->documents = array_values(array_diff($this->documents, [$document]));
        $this->completed[] = "Removed Afterburner guidelines from {$document}";
    }

    private function atomicWrite(string $relative, string $contents): void
    {
        $path = $this->path($relative);
        $this->ensureDirectory(dirname($path));

        $temporary = $path.'.afterburner-'.bin2hex(random_bytes(4));
        $mode = is_file($path) ? (@fileperms($path) ?: null) : null;

        if (@file_put_contents($temporary, $contents) === false) {
            throw WriteFailed::operation('write', $relative);
        }

        if ($mode !== null) {
            @chmod($temporary, $mode & 0777);
        }

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw WriteFailed::operation('write', $relative);
        }
    }

    private function own(string $directory, string $name): void
    {
        $this->skills[$directory] ??= [];

        if (! in_array($name, $this->skills[$directory], true)) {
            $this->skills[$directory][] = $name;
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            throw WriteFailed::operation('create the directory', $this->relative($path));
        }
    }

    /**
     * Delete a file, link, or directory tree without following links.
     */
    private function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (! @unlink($path) && ! (is_link($path) && @rmdir($path))) {
                throw WriteFailed::operation('remove', $this->relative($path));
            }

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $removed = $file->isDir() && ! $file->isLink()
                ? @rmdir($file->getPathname())
                : @unlink($file->getPathname());

            if (! $removed) {
                throw WriteFailed::operation('remove', $this->relative($file->getPathname()));
            }
        }

        if (! @rmdir($path)) {
            throw WriteFailed::operation('remove', $this->relative($path));
        }
    }

    private function path(string $relative): string
    {
        return Paths::join($this->basePath, $relative);
    }

    private function relative(string $path): string
    {
        return Paths::relative($this->basePath, $path);
    }
}
