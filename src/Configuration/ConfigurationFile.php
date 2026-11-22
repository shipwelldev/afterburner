<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Configuration;

use JsonException;
use RuntimeException;
use stdClass;

/**
 * Reads, validates, and writes the application's afterburner.json.
 *
 * The JSON document is kept as decoded objects so keys Afterburner does not
 * own (and empty objects) survive a rewrite unchanged. Only the fields being
 * changed are replaced, and nothing is written until save() is called.
 */
final class ConfigurationFile
{
    public const NAME = 'afterburner.json';

    private Settings $settings;

    private function __construct(
        private readonly string $path,
        private stdClass $document,
        private readonly bool $exists,
    ) {
        $this->settings = self::validate($document);
    }

    /**
     * Load the configuration in the given application root. A missing file is a normal first run.
     *
     * @throws InvalidConfiguration
     */
    public static function load(string $basePath): self
    {
        $path = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.self::NAME;

        if (! file_exists($path)) {
            return new self($path, new stdClass, false);
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw InvalidConfiguration::malformed(self::NAME, 'the file is not readable');
        }

        try {
            $document = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidConfiguration::malformed(self::NAME, 'it is not valid JSON ('.$exception->getMessage().')');
        }

        if (! $document instanceof stdClass) {
            throw InvalidConfiguration::malformed(self::NAME, 'the top level must be a JSON object');
        }

        return new self($path, $document, true);
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function setBase(Preset $base): self
    {
        $this->document->base = $base->value;

        return $this->refresh();
    }

    /**
     * @param  array<int, Agent>  $agents
     */
    public function setAgents(array $agents): self
    {
        $this->document->agents = array_map(
            fn (Agent $agent): string => $agent->value,
            Agent::normalize($agents),
        );

        return $this->refresh();
    }

    /**
     * Set a preference at a dotted path unless the application already chose a value.
     *
     * Used when importing choices from Boost: explicit Afterburner choices win.
     */
    public function importPreference(string $path, bool|string|null $value): self
    {
        if ($value === null) {
            return $this;
        }

        $segments = explode('.', $path);
        $key = array_pop($segments);
        $node = $this->document;

        foreach ($segments as $segment) {
            if (! property_exists($node, $segment)) {
                $node->{$segment} = new stdClass;
            }

            if (! $node->{$segment} instanceof stdClass) {
                return $this;
            }

            $node = $node->{$segment};
        }

        if (! property_exists($node, $key)) {
            $node->{$key} = $value;
        }

        return $this->refresh();
    }

    public function setPublished(PublishedState $published): self
    {
        if ($published->isEmpty() && ! property_exists($this->document, 'published')) {
            return $this;
        }

        $this->document->published = (object) $published->toJson();

        return $this->refresh();
    }

    /**
     * Write the document, replacing the file atomically.
     */
    public function save(): void
    {
        $json = json_encode($this->document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;

        if ($this->exists && is_file($this->path) && @file_get_contents($this->path) === $json) {
            return;
        }

        $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $json) === false || ! @rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException('Unable to write '.$this->path.'.');
        }
    }

    private function refresh(): self
    {
        $this->settings = self::validate($this->document);

        return $this;
    }

    /**
     * @throws InvalidConfiguration
     */
    private static function validate(stdClass $document): Settings
    {
        return new Settings(
            base: self::base($document),
            agents: self::agents($document),
            packages: self::packages($document),
            scopedGuidelines: self::scopedGuidelines($document),
            sail: self::optionalBoolean($document->integrations ?? null, 'integrations', 'sail'),
            cloud: self::optionalBoolean($document->integrations ?? null, 'integrations', 'cloud') ?? false,
            enforceTests: self::optionalBoolean($document, null, 'enforce_tests'),
            purpose: self::purpose($document),
            published: self::published($document),
        );
    }

    private static function base(stdClass $document): ?Preset
    {
        if (! property_exists($document, 'base')) {
            return null;
        }

        if (! is_string($document->base) || Preset::tryFrom($document->base) === null) {
            throw InvalidConfiguration::field(self::NAME, 'base', self::describe($document->base).' is not one of '.implode(', ', Preset::identifiers()));
        }

        return Preset::from($document->base);
    }

    /**
     * @return array<int, Agent>|null
     */
    private static function agents(stdClass $document): ?array
    {
        if (! property_exists($document, 'agents')) {
            return null;
        }

        if (! is_array($document->agents)) {
            throw InvalidConfiguration::field(self::NAME, 'agents', 'expected a list of agent identifiers, found '.self::describe($document->agents));
        }

        $agents = [];

        foreach ($document->agents as $index => $identifier) {
            $agent = is_string($identifier) ? Agent::tryFrom($identifier) : null;

            if ($agent === null) {
                throw InvalidConfiguration::field(
                    self::NAME,
                    "agents[{$index}]",
                    self::describe($identifier).' is not a supported agent ('.implode(', ', Agent::identifiers()).')',
                );
            }

            $agents[] = $agent;
        }

        return Agent::normalize($agents);
    }

    /**
     * @return array<string, PackageSwitches>
     */
    private static function packages(stdClass $document): array
    {
        if (! property_exists($document, 'packages')) {
            return [];
        }

        if (! $document->packages instanceof stdClass) {
            throw InvalidConfiguration::field(self::NAME, 'packages', 'expected an object keyed by package name, found '.self::describe($document->packages));
        }

        $packages = [];

        foreach (get_object_vars($document->packages) as $name => $switches) {
            $field = "packages.{$name}";

            if (trim((string) $name) === '') {
                throw InvalidConfiguration::field(self::NAME, 'packages', 'package names must not be empty');
            }

            if (! $switches instanceof stdClass) {
                throw InvalidConfiguration::field(self::NAME, $field, 'expected an object with `guidelines` and/or `skills` booleans, found '.self::describe($switches));
            }

            foreach (array_keys(get_object_vars($switches)) as $key) {
                if (! in_array($key, ['guidelines', 'skills'], true)) {
                    throw InvalidConfiguration::field(self::NAME, "{$field}.{$key}", 'only `guidelines` and `skills` switches are supported');
                }
            }

            $packages[(string) $name] = new PackageSwitches(
                guidelines: self::optionalBoolean($switches, $field, 'guidelines') ?? true,
                skills: self::optionalBoolean($switches, $field, 'skills') ?? true,
            );
        }

        return $packages;
    }

    private static function scopedGuidelines(stdClass $document): bool
    {
        if (! property_exists($document, 'rules')) {
            return false;
        }

        if (! $document->rules instanceof stdClass) {
            throw InvalidConfiguration::field(self::NAME, 'rules', 'expected an object, found '.self::describe($document->rules));
        }

        return self::optionalBoolean($document->rules, 'rules', 'scoped_guidelines') ?? false;
    }

    private static function purpose(stdClass $document): ?string
    {
        if (! property_exists($document, 'purpose')) {
            return null;
        }

        if (! is_string($document->purpose)) {
            throw InvalidConfiguration::field(self::NAME, 'purpose', 'expected a string describing the application, found '.self::describe($document->purpose));
        }

        return trim($document->purpose) === '' ? null : $document->purpose;
    }

    private static function optionalBoolean(mixed $container, ?string $parent, string $key): ?bool
    {
        if ($container === null) {
            return null;
        }

        $field = $parent === null ? $key : "{$parent}.{$key}";

        if (! $container instanceof stdClass) {
            throw InvalidConfiguration::field(self::NAME, (string) $parent, 'expected an object, found '.self::describe($container));
        }

        if (! property_exists($container, $key)) {
            return null;
        }

        if (! is_bool($container->{$key})) {
            throw InvalidConfiguration::field(self::NAME, $field, 'expected true or false, found '.self::describe($container->{$key}));
        }

        return $container->{$key};
    }

    private static function published(stdClass $document): PublishedState
    {
        if (! property_exists($document, 'published')) {
            return new PublishedState;
        }

        $published = $document->published;

        if (! $published instanceof stdClass) {
            throw InvalidConfiguration::field(self::NAME, 'published', 'expected an object, found '.self::describe($published));
        }

        $skills = [];

        if (property_exists($published, 'skills')) {
            if (! $published->skills instanceof stdClass) {
                throw InvalidConfiguration::field(self::NAME, 'published.skills', 'expected an object keyed by skill directory');
            }

            foreach (get_object_vars($published->skills) as $directory => $names) {
                $skills[self::relativePath((string) $directory, "published.skills.{$directory}")] = array_map(
                    fn (string $name): string => self::skillName($name, "published.skills.{$directory}"),
                    self::stringList($names, "published.skills.{$directory}"),
                );
            }
        }

        return new PublishedState(
            guidelines: array_map(
                fn (string $path): string => self::relativePath($path, 'published.guidelines'),
                self::stringList($published->guidelines ?? [], 'published.guidelines'),
            ),
            skills: $skills,
            rules: array_map(
                fn (string $path): string => self::relativePath($path, 'published.rules'),
                self::stringList($published->rules ?? [], 'published.rules'),
            ),
        );
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value, string $field): array
    {
        if (! is_array($value) || array_filter($value, fn (mixed $item): bool => ! is_string($item)) !== []) {
            throw InvalidConfiguration::field(self::NAME, $field, 'expected a list of strings');
        }

        return array_values($value);
    }

    private static function relativePath(string $path, string $field): string
    {
        $normalized = str_replace('\\', '/', $path);

        if ($normalized === ''
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:/', $normalized) === 1
            || in_array('..', explode('/', $normalized), true)) {
            throw InvalidConfiguration::field(self::NAME, $field, self::describe($path).' is not a project-relative path');
        }

        return $normalized;
    }

    private static function skillName(string $name, string $field): string
    {
        if (trim($name, ". \t\n\r\0\x0B") === '' || preg_match('#[/\\\\\0]#', $name) === 1) {
            throw InvalidConfiguration::field(self::NAME, $field, self::describe($name).' is not a valid skill name');
        }

        return $name;
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"'.$value.'"',
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_array($value) => 'a list',
            $value instanceof stdClass => 'an object',
            default => (string) json_encode($value),
        };
    }
}
