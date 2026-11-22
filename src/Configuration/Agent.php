<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Configuration;

/**
 * A supported agent integration and its project-local output locations.
 */
enum Agent: string
{
    case Claude = 'claude';
    case Codex = 'codex';
    case Cursor = 'cursor';
    case Pi = 'pi';
    case OpenCode = 'opencode';

    public function label(): string
    {
        return match ($this) {
            self::Claude => 'Claude Code',
            self::Codex => 'Codex',
            self::Cursor => 'Cursor',
            self::Pi => 'Pi',
            self::OpenCode => 'OpenCode',
        };
    }

    /**
     * The project-relative guideline document this agent reads.
     */
    public function guidelineDocument(): string
    {
        return $this === self::Claude ? 'CLAUDE.md' : 'AGENTS.md';
    }

    /**
     * The project-relative directory this agent loads skills from.
     */
    public function skillsDirectory(): string
    {
        return $this === self::Claude ? '.claude/skills' : '.agents/skills';
    }

    /**
     * Project-local files or directories whose presence suggests the agent is used.
     *
     * Detection only pre-selects an interactive choice; it never authorizes writing.
     *
     * @return array<int, string>
     */
    public function projectMarkers(): array
    {
        return match ($this) {
            self::Claude => ['.claude', 'CLAUDE.md'],
            self::Codex => ['.codex'],
            self::Cursor => ['.cursor'],
            self::Pi => ['.pi'],
            self::OpenCode => ['.opencode', 'opencode.json', 'opencode.jsonc'],
        };
    }

    /**
     * Translate a Laravel Boost agent identifier, if it maps to a supported integration.
     */
    public static function fromBoost(string $identifier): ?self
    {
        return match ($identifier) {
            'claude_code' => self::Claude,
            'codex' => self::Codex,
            'cursor' => self::Cursor,
            'pi' => self::Pi,
            'opencode' => self::OpenCode,
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function identifiers(): array
    {
        return array_map(fn (self $agent): string => $agent->value, self::cases());
    }

    /**
     * Deduplicate agents and order them canonically so saved selections are stable.
     *
     * @param  iterable<self>  $agents
     * @return array<int, self>
     */
    public static function normalize(iterable $agents): array
    {
        $selected = [];

        foreach ($agents as $agent) {
            $selected[$agent->value] = $agent;
        }

        return array_values(array_filter(
            self::cases(),
            fn (self $agent): bool => isset($selected[$agent->value]),
        ));
    }
}
