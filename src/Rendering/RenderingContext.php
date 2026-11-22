<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Rendering;

use Laravel\Roster\Package;
use Shipwell\Afterburner\Configuration\Settings;
use Shipwell\Afterburner\Discovery\PackageInventory;

/**
 * Builds the template context from the actual application and its saved preferences.
 */
final class RenderingContext
{
    /**
     * Applications with fewer detectable tests than this do not get the test-enforcement guideline.
     */
    public const MINIMUM_TEST_COUNT = 6;

    public static function assist(string $basePath, Settings $settings, PackageInventory $inventory): GuidelineAssist
    {
        $config = new GuidelineConfig;
        $config->usesSail = self::usesSail($basePath, $settings);
        $config->usesCloud = $settings->cloud;
        $config->enforceTests = self::enforcesTests($basePath, $settings);
        $config->hasSkills = true;
        $config->hasMcp = false;
        $config->purpose = $settings->purpose;
        $config->aiGuidelines = array_values(array_map(
            fn (Package $package): string => $package->name(),
            array_filter($inventory->thirdParty(), fn (Package $package): bool => $settings->guidelinesEnabledFor($package->name())),
        ));

        return new GuidelineAssist($inventory->project(), $config, collect(), $basePath);
    }

    public static function usesSail(string $basePath, Settings $settings): bool
    {
        return $settings->sail ?? (Sail::isInstalled($basePath) && Sail::isActive());
    }

    public static function enforcesTests(string $basePath, Settings $settings): bool
    {
        return $settings->enforceTests ?? self::hasTestSuite($basePath);
    }

    /**
     * Whether Laravel Herd serves this application: a `.test` APP_URL with Herd installed and Sail not in use.
     */
    public static function usesHerd(string $basePath, Settings $settings): bool
    {
        if (! str_contains((string) config('app.url'), '.test') || self::usesSail($basePath, $settings)) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $home = str_replace('\\', '/', (string) ($_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? ''));

            return $home !== '' && is_dir($home.'/.config/herd');
        }

        return file_exists('/Applications/Herd.app/Contents/MacOS/Herd');
    }

    /**
     * Whether the application has a test runner and at least a handful of test cases.
     */
    public static function hasTestSuite(string $basePath): bool
    {
        if (! is_file($basePath.'/vendor/bin/phpunit') && ! is_file($basePath.'/vendor/bin/pest')) {
            return false;
        }

        if (! is_dir($basePath.'/tests')) {
            return false;
        }

        $count = 0;

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($basePath.'/tests', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $code = (string) @file_get_contents($file->getPathname());
            $count += preg_match_all('/^\s*(?:(?:test|it)\s*\(|(?:public\s+)?function\s+test\w*\s*\(|#\[\s*(?:\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?Test\s*\])/m', $code);

            if ($count >= self::MINIMUM_TEST_COUNT) {
                return true;
            }
        }

        return false;
    }
}
