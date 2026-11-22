<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Installation;

use Shipwell\Afterburner\Configuration\Agent;
use Shipwell\Afterburner\Configuration\InvalidConfiguration;
use Shipwell\Afterburner\Configuration\Preset;

/**
 * Parses the explicit choices accepted on the command line.
 */
final class CommandInput
{
    /**
     * Parse a comma-separated --agents value. Returns null when the option was not given.
     *
     * @return array<int, Agent>|null
     *
     * @throws InvalidConfiguration
     */
    public static function agents(mixed $value): ?array
    {
        if ($value === null || $value === false) {
            return null;
        }

        $agents = [];

        foreach (explode(',', (string) $value) as $token) {
            $token = trim($token);

            if ($token === '') {
                throw InvalidConfiguration::input('--agents', 'blank agent identifier in "'.$value.'"; use a comma-separated list such as claude,codex');
            }

            $agent = Agent::tryFrom($token);

            if ($agent === null) {
                throw InvalidConfiguration::input('--agents', "unknown agent \"{$token}\"; supported agents are ".implode(', ', Agent::identifiers()));
            }

            $agents[] = $agent;
        }

        return Agent::normalize($agents);
    }

    /**
     * @throws InvalidConfiguration
     */
    public static function base(mixed $value): ?Preset
    {
        if ($value === null || $value === false) {
            return null;
        }

        return Preset::tryFrom(trim((string) $value))
            ?? throw InvalidConfiguration::input('--base', "unknown preset \"{$value}\"; supported presets are ".implode(', ', Preset::identifiers()));
    }
}
