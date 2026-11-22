<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Replacement;

/**
 * Whether laravel/boost is installed and where the application requires it.
 */
final readonly class BoostInstallation
{
    public const PACKAGE = 'laravel/boost';

    public function __construct(
        public bool $installed,
        public ?string $requirement,
    ) {
        //
    }

    public static function inspect(string $basePath): self
    {
        $composer = self::json($basePath.'/composer.json') ?? [];

        $requirement = match (true) {
            isset($composer['require-dev'][self::PACKAGE]) => 'require-dev',
            isset($composer['require'][self::PACKAGE]) => 'require',
            default => null,
        };

        return new self(self::installedIn($basePath, $composer), $requirement);
    }

    /**
     * @param  array<string, mixed>  $composer
     */
    private static function installedIn(string $basePath, array $composer): bool
    {
        $vendor = is_string($composer['config']['vendor-dir'] ?? null) ? $composer['config']['vendor-dir'] : 'vendor';
        $vendor = str_starts_with($vendor, '/') ? $vendor : $basePath.'/'.$vendor;

        $installed = self::json($vendor.'/composer/installed.json');

        if ($installed !== null) {
            $packages = $installed['packages'] ?? $installed;

            foreach (is_array($packages) ? $packages : [] as $package) {
                if (is_array($package) && ($package['name'] ?? null) === self::PACKAGE) {
                    return true;
                }
            }

            return false;
        }

        return is_dir($vendor.'/laravel/boost');
    }

    /**
     * @return array<mixed>|null
     */
    private static function json(string $path): ?array
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;
        $decoded = $contents === false ? null : json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }
}
