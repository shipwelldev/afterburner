<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Replacement;

use Shipwell\Afterburner\Publication\Paths;

/**
 * Resolves configured paths against the application root.
 */
final class ProjectPath
{
    /**
     * The project-relative form of a configured path, or null when it is outside the application.
     */
    public static function relative(string $basePath, string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', $basePath), '/');

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized) === 1 || str_starts_with($normalized, '~')) {
            if (! str_starts_with($normalized, $base.'/')) {
                return null;
            }

            $normalized = substr($normalized, strlen($base) + 1);
        }

        $normalized = (string) preg_replace('#^(\./)+#', '', $normalized);
        $normalized = rtrim($normalized, '/');

        return Paths::isContained($normalized) ? $normalized : null;
    }
}
