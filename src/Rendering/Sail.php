<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Rendering;

/**
 * Laravel Sail detection and command prefixes (behavior matches Laravel Boost v2.10.4).
 */
final class Sail
{
    public const DEFAULT_BINARY_PATH = 'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'sail';

    public static function binaryPath(): string
    {
        $configured = config('boost.executable_paths.sail');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_BINARY_PATH;
    }

    public static function command(string $command): string
    {
        return self::binaryPath().' '.$command;
    }

    public static function isInstalled(string $basePath): bool
    {
        $binary = self::binaryPath();
        $binaryExists = file_exists($binary) || file_exists($basePath.DIRECTORY_SEPARATOR.$binary);

        if (! $binaryExists) {
            return false;
        }

        foreach (['compose.yml', 'compose.yaml', 'docker-compose.yml', 'docker-compose.yaml'] as $file) {
            if (file_exists($basePath.DIRECTORY_SEPARATOR.$file)) {
                return true;
            }
        }

        return false;
    }

    public static function isActive(): bool
    {
        if (getenv('REMOTE_CONTAINERS') === 'true') {
            return false;
        }

        return getenv('LARAVEL_SAIL') === '1' || get_current_user() === 'sail';
    }
}
