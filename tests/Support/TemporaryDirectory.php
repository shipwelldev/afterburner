<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class TemporaryDirectory
{
    public static function make(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $path = $base.DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(6));

        if (! mkdir($path, 0755, true)) {
            throw new RuntimeException("Unable to create temporary directory [{$path}].");
        }

        return realpath($path) ?: $path;
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isLink()) {
                @unlink($file->getPathname());
            } elseif (! $file->isDir()) {
                @unlink($file->getPathname());
            } else {
                @chmod($file->getPathname(), 0755);
                @rmdir($file->getPathname());
            }
        }

        @rmdir($path);
    }
}
