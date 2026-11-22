<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Compatibility;

use Laravel\Roster\Package;
use Shipwell\Afterburner\Discovery\PackageRegistry;

/**
 * Stand-in for `Laravel\Boost\Support\PackageRegistry`, which Boost-authored
 * templates reference for package-name constants. It is aliased to that name
 * only while rendering and only when Boost itself is not installed.
 */
final class BoostPackageRegistry
{
    public const AI = 'laravel/ai';

    public const BOOST = 'laravel/boost';

    public const FLUXUI_FREE = 'livewire/flux';

    public const FLUXUI_PRO = 'livewire/flux-pro';

    public const INERTIA_LARAVEL = 'inertiajs/inertia-laravel';

    public const INERTIA_REACT = '@inertiajs/react';

    public const INERTIA_SVELTE = '@inertiajs/svelte';

    public const INERTIA_VUE = '@inertiajs/vue3';

    public const LARAVEL = 'laravel/framework';

    public const LIVEWIRE = 'livewire/livewire';

    public const MCP = 'laravel/mcp';

    public const PEST = 'pestphp/pest';

    public const PHPUNIT = 'phpunit/phpunit';

    public const PINT = 'laravel/pint';

    public const SAIL = 'laravel/sail';

    public static function guidelineName(string $package): string
    {
        return PackageRegistry::guidelineName($package);
    }

    public static function rosterName(string $package): string
    {
        return strtoupper(str_replace('-', '_', self::guidelineName($package)));
    }

    public static function isFirstParty(Package $package): bool
    {
        return PackageRegistry::isFirstParty($package);
    }

    public static function boostPath(Package $package, string $subpath): ?string
    {
        return PackageRegistry::boostPath($package, $subpath);
    }
}
