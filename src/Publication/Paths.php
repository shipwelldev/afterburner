<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

/**
 * Project-relative path helpers.
 */
final class Paths
{
    public static function relative(string $basePath, string $path): string
    {
        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, $base) ? substr($normalized, strlen($base)) : $normalized;
    }

    public static function join(string ...$segments): string
    {
        return implode(DIRECTORY_SEPARATOR, array_map(
            fn (string $segment): string => rtrim($segment, '/\\'),
            array_filter($segments, fn (string $segment): bool => $segment !== ''),
        ));
    }

    /**
     * Whether a published name or relative path stays inside its destination.
     */
    public static function isContained(string $relative): bool
    {
        $normalized = str_replace('\\', '/', $relative);

        return $normalized !== ''
            && ! str_starts_with($normalized, '/')
            && preg_match('/^[A-Za-z]:/', $normalized) !== 1
            && ! in_array('..', explode('/', $normalized), true)
            && ! str_contains($normalized, "\0");
    }

    public static function isValidSkillName(string $name): bool
    {
        return trim($name, ". \t\n\r\0\x0B") !== ''
            && preg_match('#[/\\\\\0]#', $name) !== 1
            && ! str_contains($name, '..');
    }
}
