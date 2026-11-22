<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Replacement;

use Shipwell\Afterburner\Configuration\Agent;
use Shipwell\Afterburner\Publication\Paths;
use Shipwell\Afterburner\Publication\PublicationFailed;
use Shipwell\Afterburner\Rules\RuleIndex;

/**
 * Reads everything needed to replace Boost while its files are still present:
 * boost.json, `config('boost')`, Boost-managed guideline blocks, Boost-tracked
 * skills, Boost-generated rules, and project-local MCP locations.
 *
 * Boost records no file hashes or destination manifest, so ownership is
 * inferred only from its guideline delimiters, the skill names it tracked in
 * boost.json, and its managed `.ai/rules/boost` directory.
 */
final class BoostCapture
{
    public const BLOCK_OPEN = '<laravel-boost-guidelines>';

    public const BLOCK_CLOSE = '</laravel-boost-guidelines>';

    /**
     * Default skill directories of the Boost agents Afterburner supports.
     *
     * @var array<string, string>
     */
    private const SKILL_DIRECTORIES = [
        'claude_code' => '.claude/skills',
        'codex' => '.agents/skills',
        'cursor' => '.cursor/skills',
        'pi' => '.pi/skills',
        'opencode' => '.agents/skills',
    ];

    /**
     * @param  array<int, Agent>  $agents  supported agents translated from boost.json
     * @param  array<int, string>  $unsupportedAgents
     * @param  array{sail: bool|null, cloud: bool|null, enforce_tests: bool|null, scoped_guidelines: bool|null, purpose: string|null}  $preferences
     * @param  array<int, string>  $documents  documents with a Boost guideline block
     * @param  array<string, array<int, string>>  $skills  skill directory => Boost-tracked skill names present there
     * @param  array<int, string>  $rules  Boost-generated rule files
     * @param  array<int, array{0: string, 1: string, 2: string}>  $mcpLocations
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public bool $boostJson,
        public array $agents,
        public array $unsupportedAgents,
        public array $preferences,
        public array $documents,
        public array $skills,
        public array $rules,
        public array $mcpLocations,
        public array $notes,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $configuration  `config('boost')`, available while Boost is installed
     *
     * @throws PublicationFailed when boost.json or a Boost block cannot be read safely
     */
    public static function capture(string $basePath, array $configuration): self
    {
        $notes = [];
        $boost = self::boostJson($basePath);
        $identifiers = array_values(array_filter((array) ($boost['agents'] ?? []), 'is_string'));

        $agents = [];
        $unsupported = [];

        foreach ($identifiers as $identifier) {
            $agent = Agent::fromBoost($identifier);

            if ($agent === null) {
                $unsupported[] = $identifier;
            } else {
                $agents[] = $agent;
            }
        }

        if ($unsupported !== []) {
            $notes[] = 'Boost was configured for '.implode(', ', $unsupported).', which Afterburner does not support. Their guideline, skill, and MCP output was left unchanged.';
        }

        $agentConfiguration = is_array($configuration['agents'] ?? null) ? $configuration['agents'] : [];
        $outside = [];

        $documents = self::documents($basePath, $identifiers, $agentConfiguration, $outside);
        $skills = self::skills($basePath, $identifiers, $agentConfiguration, (array) ($boost['skills'] ?? []), $outside);
        $mcpLocations = McpCleanup::locationsFor($basePath, $agentConfiguration, $outside);

        foreach (array_unique($outside) as $path) {
            $notes[] = "Boost is configured to write {$path}, which is outside this application. Afterburner does not edit files outside the application; clean it up by hand.";
        }

        $packages = array_values(array_filter((array) ($boost['packages'] ?? []), 'is_string'));

        if ($packages !== []) {
            $notes[] = 'Boost\'s selected third-party packages ('.implode(', ', $packages).') were not imported: Afterburner includes direct dependency assets by default. Switch packages off under `packages` in afterburner.json if needed.';
        }

        if (($boost['guidelines'] ?? true) === false) {
            $notes[] = 'Boost was configured without guidelines; Afterburner publishes a managed guideline block for each selected agent.';
        }

        $skillNames = array_values(array_filter((array) ($boost['skills'] ?? []), 'is_string'));

        return new self(
            boostJson: $boost !== null,
            agents: Agent::normalize($agents),
            unsupportedAgents: $unsupported,
            preferences: [
                'sail' => is_bool($boost['sail'] ?? null) ? $boost['sail'] : null,
                'cloud' => is_bool($boost['cloud'] ?? null) ? $boost['cloud'] : ($skillNames !== [] ? true : null),
                'enforce_tests' => is_bool($configuration['enforce_tests'] ?? null) ? $configuration['enforce_tests'] : null,
                'scoped_guidelines' => ($configuration['rules']['enabled'] ?? true) !== false && ($configuration['rules']['scoped_guidelines'] ?? false) === true ? true : null,
                'purpose' => is_string($configuration['purpose'] ?? null) && trim($configuration['purpose']) !== '' ? $configuration['purpose'] : null,
            ],
            documents: $documents,
            skills: $skills,
            rules: self::rules($basePath),
            mcpLocations: $mcpLocations,
            notes: $notes,
        );
    }

    public function foundBoost(): bool
    {
        return $this->boostJson || $this->documents !== [] || $this->skills !== [] || $this->rules !== [];
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws PublicationFailed
     */
    private static function boostJson(string $basePath): ?array
    {
        $path = $basePath.'/boost.json';

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw PublicationFailed::source('boost.json', 'it is not a valid JSON object, so Boost\'s choices cannot be captured. Fix it or move it aside, then run the replacement again');
        }

        return $decoded;
    }

    /**
     * Guideline documents of supported agents that contain a Boost block.
     *
     * @param  array<int, string>  $identifiers
     * @param  array<string, mixed>  $agentConfiguration
     * @param  array<int, string>  $outside
     * @return array<int, string>
     *
     * @throws PublicationFailed
     */
    private static function documents(string $basePath, array $identifiers, array $agentConfiguration, array &$outside): array
    {
        $candidates = ['CLAUDE.md', 'AGENTS.md'];

        foreach ($identifiers as $identifier) {
            $configured = $agentConfiguration[$identifier]['guidelines_path'] ?? null;

            if (Agent::fromBoost($identifier) === null || ! is_string($configured) || $configured === '') {
                continue;
            }

            $relative = ProjectPath::relative($basePath, $configured);

            if ($relative === null) {
                $outside[] = $configured;
            } else {
                $candidates[] = $relative;
            }
        }

        $documents = [];

        foreach (array_unique($candidates) as $document) {
            $path = Paths::join($basePath, $document);
            $contents = is_file($path) ? @file_get_contents($path) : false;

            if ($contents !== false && self::locateBlock($contents, $document) !== null) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /**
     * Find a Boost guideline block's byte range.
     *
     * @return array{start: int, end: int}|null
     *
     * @throws PublicationFailed
     */
    public static function locateBlock(string $contents, string $document): ?array
    {
        preg_match_all('/^[ \t]*<laravel-boost-guidelines>[ \t]*(?=\r?$)/m', $contents, $opens, PREG_OFFSET_CAPTURE);
        preg_match_all('/^[ \t]*<\/laravel-boost-guidelines>[ \t]*(?=\r?$)/m', $contents, $closes, PREG_OFFSET_CAPTURE);

        if ($opens[0] === [] && $closes[0] === []) {
            return null;
        }

        if (count($opens[0]) !== 1 || count($closes[0]) !== 1 || $closes[0][0][1] < $opens[0][0][1]) {
            throw PublicationFailed::target($document, 'the Laravel Boost guideline delimiters are malformed, so the Boost section cannot be replaced safely. Repair or remove it by hand');
        }

        return ['start' => $opens[0][0][1], 'end' => $closes[0][0][1] + strlen($closes[0][0][0])];
    }

    /**
     * Boost-tracked skill names present in supported agents' skill directories.
     *
     * @param  array<int, string>  $identifiers
     * @param  array<string, mixed>  $agentConfiguration
     * @param  array<int, mixed>  $names
     * @param  array<int, string>  $outside
     * @return array<string, array<int, string>>
     */
    private static function skills(string $basePath, array $identifiers, array $agentConfiguration, array $names, array &$outside): array
    {
        $names = array_values(array_filter($names, fn (mixed $name): bool => is_string($name) && Paths::isValidSkillName($name)));
        $skills = [];

        foreach ($identifiers as $identifier) {
            if (! isset(self::SKILL_DIRECTORIES[$identifier])) {
                continue;
            }

            $configured = $agentConfiguration[$identifier]['skills_path'] ?? null;
            $directory = is_string($configured) && $configured !== ''
                ? ProjectPath::relative($basePath, $configured)
                : self::SKILL_DIRECTORIES[$identifier];

            if ($directory === null) {
                $outside[] = (string) $configured;

                continue;
            }

            foreach ($names as $name) {
                $path = Paths::join($basePath, $directory, $name);

                if (is_dir($path) || is_link($path)) {
                    $skills[$directory][] = $name;
                }
            }
        }

        return array_map(fn (array $names): array => array_values(array_unique($names)), $skills);
    }

    /**
     * @return array<int, string>
     */
    private static function rules(string $basePath): array
    {
        return array_values(array_map(
            fn (string $file): string => Paths::relative($basePath, $file),
            glob(Paths::join($basePath, RuleIndex::MANAGED_DIRECTORY).DIRECTORY_SEPARATOR.'*.md') ?: [],
        ));
    }
}
