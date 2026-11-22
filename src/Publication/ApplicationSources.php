<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Application-authored sources below `.ai`.
 */
final class ApplicationSources
{
    public function __construct(private readonly string $basePath)
    {
        //
    }

    public function guidelineDirectory(): string
    {
        return Paths::join($this->basePath, '.ai', 'guidelines');
    }

    /**
     * Guideline files below `.ai/guidelines`, keyed by their path without extension.
     *
     * A Blade variant replaces a Markdown file with the same relative path.
     *
     * @return array<string, string> relative key => absolute path, sorted by key
     */
    public function guidelineFiles(): array
    {
        return self::guidelineFilesIn($this->guidelineDirectory());
    }

    /**
     * @return array<string, string>
     */
    public static function guidelineFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
        );

        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));

            if (! $file->isFile() || in_array('skill', explode('/', dirname($relative)), true)) {
                continue;
            }

            if (preg_match('/^(?<key>.+)\.(?<extension>blade\.php|md)$/', $relative, $matches) !== 1) {
                continue;
            }

            if ($matches['extension'] === 'md' && isset($files[$matches['key']])) {
                continue;
            }

            $files[$matches['key']] = $file->getPathname();
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Explicit application skills: each directory below `.ai/skills` with an entry point.
     *
     * @return array<int, SkillSource>
     */
    public function explicitSkills(): array
    {
        return self::skillsIn(Paths::join($this->basePath, '.ai', 'skills'), Origin::Application);
    }

    /**
     * Directories below `.ai/skills` that have no SKILL.md or SKILL.blade.php.
     *
     * @return array<int, string>
     */
    public function skillDirectoriesWithoutEntry(): array
    {
        return array_values(array_filter(
            self::directoriesIn(Paths::join($this->basePath, '.ai', 'skills')),
            fn (string $directory): bool => SkillSource::in($directory, Origin::Application) === null,
        ));
    }

    /**
     * @return array<int, SkillSource>
     */
    public static function skillsIn(string $directory, Origin $origin, ?string $package = null): array
    {
        return array_values(array_filter(array_map(
            fn (string $skill): ?SkillSource => SkillSource::in($skill, $origin, $package),
            self::directoriesIn($directory),
        )));
    }

    /**
     * @return array<int, string>
     */
    private static function directoriesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $directories = glob($directory.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];

        sort($directories, SORT_STRING);

        return $directories;
    }
}
