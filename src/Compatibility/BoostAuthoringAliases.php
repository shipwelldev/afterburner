<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Compatibility;

/**
 * Lets Boost-authored templates reference Boost's public authoring classes
 * when Boost is not installed. The fallback autoloader runs after Composer's,
 * so an installed Boost always provides its own classes.
 */
final class BoostAuthoringAliases
{
    /** @var array<string, class-string> */
    private const ALIASES = [
        'Laravel\\Boost\\Support\\PackageRegistry' => BoostPackageRegistry::class,
    ];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        spl_autoload_register(static function (string $class): void {
            if (isset(self::ALIASES[$class])) {
                class_alias(self::ALIASES[$class], $class);
            }
        });

        self::$registered = true;
    }
}
