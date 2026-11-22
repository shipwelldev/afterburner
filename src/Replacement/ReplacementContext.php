<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Replacement;

use RuntimeException;
use Shipwell\Afterburner\Configuration\Agent;
use Shipwell\Afterburner\Configuration\Preset;
use Shipwell\Afterburner\Publication\Adoption;

/**
 * Operational state for an in-progress Boost replacement.
 *
 * It carries the captured and resolved choices across the Composer removal
 * and into the fresh finalization process (and any Composer callback that
 * runs `boost:update` meanwhile). It is deleted once the replacement
 * finishes; it is never a second publication inventory.
 */
final readonly class ReplacementContext
{
    public const FILE = 'storage/framework/afterburner-replacement.json';

    /**
     * @param  array<int, Agent>  $agents
     * @param  array{sail: bool|null, cloud: bool|null, enforce_tests: bool|null, scoped_guidelines: bool|null, purpose: string|null}  $preferences
     * @param  array<int, array{0: string, 1: string, 2: string}>  $mcpLocations
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public array $agents,
        public Preset $base,
        public array $preferences,
        public Adoption $adoption,
        public array $mcpLocations,
        public array $notes,
        public string $startedAt,
    ) {
        //
    }

    public static function path(string $basePath): string
    {
        return rtrim($basePath, '/').'/'.self::FILE;
    }

    public static function exists(string $basePath): bool
    {
        return is_file(self::path($basePath));
    }

    /**
     * @throws RuntimeException when the file exists but cannot be read
     */
    public static function load(string $basePath): ?self
    {
        if (! self::exists($basePath)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents(self::path($basePath)), true);

        if (! is_array($data) || ($data['version'] ?? null) !== 1) {
            throw new RuntimeException(self::FILE.' is not a readable Afterburner replacement context. Delete it and run `php artisan afterburner:install --replace-boost` again.');
        }

        return new self(
            agents: Agent::normalize(array_filter(array_map(fn (mixed $agent): ?Agent => is_string($agent) ? Agent::tryFrom($agent) : null, (array) ($data['agents'] ?? [])))),
            base: Preset::tryFrom((string) ($data['base'] ?? '')) ?? Preset::Boost,
            preferences: [
                'sail' => self::bool($data['preferences']['sail'] ?? null),
                'cloud' => self::bool($data['preferences']['cloud'] ?? null),
                'enforce_tests' => self::bool($data['preferences']['enforce_tests'] ?? null),
                'scoped_guidelines' => self::bool($data['preferences']['scoped_guidelines'] ?? null),
                'purpose' => is_string($data['preferences']['purpose'] ?? null) ? $data['preferences']['purpose'] : null,
            ],
            adoption: new Adoption(
                documents: array_values(array_filter((array) ($data['adoption']['documents'] ?? []), 'is_string')),
                skills: array_map(fn (mixed $names): array => array_values(array_filter((array) $names, 'is_string')), (array) ($data['adoption']['skills'] ?? [])),
                rules: array_values(array_filter((array) ($data['adoption']['rules'] ?? []), 'is_string')),
            ),
            mcpLocations: array_values(array_filter((array) ($data['mcp_locations'] ?? []), fn (mixed $location): bool => is_array($location) && count($location) === 3)),
            notes: array_values(array_filter((array) ($data['notes'] ?? []), 'is_string')),
            startedAt: (string) ($data['started_at'] ?? ''),
        );
    }

    public function save(string $basePath): void
    {
        $path = self::path($basePath);

        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }

        $json = json_encode([
            'version' => 1,
            'started_at' => $this->startedAt,
            'agents' => array_map(fn (Agent $agent): string => $agent->value, $this->agents),
            'base' => $this->base->value,
            'preferences' => $this->preferences,
            'adoption' => [
                'documents' => $this->adoption->documents,
                'skills' => (object) $this->adoption->skills,
                'rules' => $this->adoption->rules,
            ],
            'mcp_locations' => $this->mcpLocations,
            'notes' => $this->notes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($path, $json.PHP_EOL) === false) {
            throw new RuntimeException('Unable to write '.self::FILE.'.');
        }
    }

    public static function delete(string $basePath): void
    {
        if (self::exists($basePath)) {
            @unlink(self::path($basePath));
        }
    }

    private static function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
