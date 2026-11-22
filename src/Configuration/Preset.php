<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Configuration;

/**
 * The Afterburner-supplied content selection, independent of application and dependency content.
 */
enum Preset: string
{
    case Boost = 'boost';
    case Afterburner = 'afterburner';
    case Core = 'core';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Boost => 'boost — core skills plus the released Laravel Boost guidance',
            self::Afterburner => 'afterburner — core skills plus Afterburner\'s curated Boost guidance',
            self::Core => 'core — Afterburner\'s core skills that replace Boost\'s MCP tools, nothing else',
            self::None => 'none — no bundled guidance; application and package content only',
        };
    }

    /**
     * The preset used when no explicit choice has been saved or given.
     */
    public static function default(bool $replacingBoost = false): self
    {
        return $replacingBoost ? self::Boost : self::Afterburner;
    }

    /**
     * @return array<int, string>
     */
    public static function identifiers(): array
    {
        return array_map(fn (self $preset): string => $preset->value, self::cases());
    }
}
