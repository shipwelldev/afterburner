<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Discovery;

use Laravel\Roster\Enums\PackageSource;
use Laravel\Roster\Package;

/**
 * Package identity and applicability rules from the pinned Laravel Boost baseline (v2.10.4, MIT).
 */
final class PackageRegistry
{
    public const BOOST = 'laravel/boost';

    public const FLUXUI_FREE = 'livewire/flux';

    public const FLUXUI_PRO = 'livewire/flux-pro';

    public const INERTIA_LARAVEL = 'inertiajs/inertia-laravel';

    public const INERTIA_REACT = '@inertiajs/react';

    public const INERTIA_SVELTE = '@inertiajs/svelte';

    public const INERTIA_VUE = '@inertiajs/vue3';

    public const LIVEWIRE = 'livewire/livewire';

    public const MCP = 'laravel/mcp';

    public const PEST = 'pestphp/pest';

    public const PHPUNIT = 'phpunit/phpunit';

    public const SAIL = 'laravel/sail';

    /** @var array<string, string> */
    private const GUIDELINE_NAMES = [
        '@inertiajs/react' => 'inertia-react',
        '@inertiajs/svelte' => 'inertia-svelte',
        '@inertiajs/vue3' => 'inertia-vue',
        'inertiajs/inertia-laravel' => 'inertia-laravel',
        'laravel/boost' => 'boost',
        'laravel/folio' => 'folio',
        'laravel/framework' => 'laravel',
        'laravel/mcp' => 'mcp',
        'laravel/pennant' => 'pennant',
        'laravel/pint' => 'pint',
        'laravel/sail' => 'sail',
        'laravel/wayfinder' => 'wayfinder',
        'livewire/flux' => 'fluxui-free',
        'livewire/flux-pro' => 'fluxui-pro',
        'livewire/livewire' => 'livewire',
        'livewire/volt' => 'volt',
        'pestphp/pest' => 'pest',
        'phpunit/phpunit' => 'phpunit',
        'tailwindcss' => 'tailwindcss',
    ];

    /** @var array<int, string> */
    private const COMPOSER_FIRST_PARTY_SCOPES = ['laravel'];

    /** @var array<int, string> */
    private const COMPOSER_FIRST_PARTY = [
        'livewire/livewire',
        'livewire/flux',
        'livewire/flux-pro',
        'livewire/volt',
        'inertiajs/inertia-laravel',
        'pestphp/pest',
        'phpunit/phpunit',
    ];

    /** @var array<int, string> */
    private const NPM_FIRST_PARTY_SCOPES = ['@inertiajs', '@laravel'];

    /** @var array<int, string> */
    private const NPM_FIRST_PARTY = ['laravel-echo', 'laravel-precognition', 'laravel-vite-plugin'];

    /**
     * Packages that never contribute package guidance: Boost itself, and Sail (opt-in integration).
     *
     * @var array<int, string>
     */
    private const NEVER_PACKAGE_SOURCES = [self::BOOST, self::SAIL];

    /**
     * Packages that only contribute when they are a direct requirement.
     *
     * @var array<int, string>
     */
    private const MUST_BE_DIRECT = [self::MCP, self::LIVEWIRE];

    /**
     * A present key package suppresses the redundant alternatives listed for it.
     *
     * @var array<string, array<int, string>>
     */
    private const SUPPRESSES = [
        self::PEST => [self::PHPUNIT],
        self::FLUXUI_PRO => [self::FLUXUI_FREE],
    ];

    public static function guidelineName(string $package): string
    {
        return self::GUIDELINE_NAMES[$package]
            ?? str_replace(['@', '/', '_'], ['', '-', '-'], strtolower($package));
    }

    public static function isFirstParty(Package $package): bool
    {
        [$scopes, $names] = $package->source() === PackageSource::Composer
            ? [self::COMPOSER_FIRST_PARTY_SCOPES, self::COMPOSER_FIRST_PARTY]
            : [self::NPM_FIRST_PARTY_SCOPES, self::NPM_FIRST_PARTY];

        foreach ($scopes as $scope) {
            if (str_starts_with($package->name(), $scope.'/')) {
                return true;
            }
        }

        return in_array($package->name(), $names, true);
    }

    /**
     * Whether a package is skipped for package-specific guidance and skills.
     *
     * @param  array<int, string>  $installed  names of all installed packages
     */
    public static function isSuppressed(Package $package, array $installed): bool
    {
        if (in_array($package->name(), self::NEVER_PACKAGE_SOURCES, true)) {
            return true;
        }

        foreach (self::SUPPRESSES as $preferred => $alternatives) {
            if (in_array($package->name(), $alternatives, true) && in_array($preferred, $installed, true)) {
                return true;
            }
        }

        return ! $package->isDirect() && in_array($package->name(), self::MUST_BE_DIRECT, true);
    }

    /**
     * The package's `resources/boost/<subpath>` directory, when it exists.
     */
    public static function boostPath(Package $package, string $subpath): ?string
    {
        if ($package->path() === null) {
            return null;
        }

        $path = implode(DIRECTORY_SEPARATOR, [$package->path(), 'resources', 'boost', $subpath]);

        return is_dir($path) ? $path : null;
    }
}
