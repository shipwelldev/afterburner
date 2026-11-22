<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Rules;

use Shipwell\Afterburner\Publication\Paths;

/**
 * Discovers project rules and renders the rules index.
 *
 * Discovery matches Boost: top-level Markdown files in `.ai/rules` (except
 * the index itself) plus managed scoped rules in `.ai/rules/boost`.
 */
final class RuleIndex
{
    public const DIRECTORY = '.ai/rules';

    public const INDEX = '.ai/rules/index.md';

    public const MANAGED_DIRECTORY = '.ai/rules/boost';

    public function __construct(private readonly string $basePath)
    {
        //
    }

    public function exists(): bool
    {
        return is_dir($this->path(self::DIRECTORY));
    }

    /**
     * Rule files in deterministic project-relative order.
     *
     * @param  array<string, string>  $overrides  relative path => contents to use instead of the file on disk (null removes it)
     * @return array<int, RuleFile>
     */
    public function files(array $overrides = []): array
    {
        $relatives = [];

        foreach ([self::DIRECTORY, self::MANAGED_DIRECTORY] as $directory) {
            foreach (glob($this->path($directory).DIRECTORY_SEPARATOR.'*.md') ?: [] as $file) {
                $relative = Paths::relative($this->basePath, $file);

                if ($relative !== self::INDEX && is_file($file)) {
                    $relatives[$relative] = $file;
                }
            }
        }

        foreach ($overrides as $relative => $contents) {
            if ($contents === null) {
                unset($relatives[$relative]);
            } else {
                $relatives[$relative] = $this->path($relative);
            }
        }

        ksort($relatives, SORT_STRING);

        $files = [];

        foreach ($relatives as $relative => $path) {
            $files[] = array_key_exists($relative, $overrides)
                ? RuleFile::fromContents((string) $overrides[$relative], $path, $relative)
                : RuleFile::read($path, $relative);
        }

        return $files;
    }

    /**
     * @param  array<int, RuleFile>  $files
     */
    public static function render(array $files): string
    {
        $rows = array_map(
            fn (RuleFile $file): string => '| '.implode(', ', array_map(self::cell(...), $file->paths)).' | '.self::cell($file->relative).' |',
            array_values(array_filter($files, fn (RuleFile $file): bool => $file->indexable())),
        );

        $table = $rows === []
            ? 'No rules recorded yet.'
            : "| Applies to | Rule file |\n| --- | --- |\n".implode("\n", $rows);

        return "# Project Rules Index\n\n"
            ."Before planning or editing a file, find every row whose globs match the file's path and read each of those rule files. A file can match several rows; follow all of the matching rules.\n\n"
            .$table."\n";
    }

    private static function cell(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }

    public function path(string $relative): string
    {
        return Paths::join($this->basePath, $relative);
    }
}
