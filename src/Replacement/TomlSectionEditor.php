<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Replacement;

/**
 * Finds and removes a `[container.key]` table (and its `[container.key.*]`
 * subtables) from a TOML document, leaving every other line untouched.
 */
final class TomlSectionEditor
{
    /**
     * @return array{start: int, end: int, command: mixed, args: mixed}|null|false null when absent, false when the entry uses unsupported syntax
     */
    public static function find(string $contents, string $container, string $key): array|false|null
    {
        $lines = explode("\n", $contents);
        $header = self::headerPattern($container, $key);
        $start = null;
        $end = count($lines);

        foreach ($lines as $index => $line) {
            if ($start === null) {
                if (preg_match($header, $line) === 1) {
                    $start = $index;
                }

                continue;
            }

            if (preg_match('/^\s*\[/', $line) === 1 && preg_match(self::subtablePattern($container, $key), $line) !== 1) {
                $end = $index;

                break;
            }
        }

        if ($start === null) {
            // The entry might be written as dotted keys or an inline table, which is not edited automatically.
            return preg_match('/^\s*'.preg_quote($container, '/').'\s*[.=]|^\s*\[\s*'.preg_quote($container, '/').'\s*\]/m', $contents) === 1
                && str_contains($contents, $key) ? false : null;
        }

        // Blank and comment lines directly above the next table belong to that table.
        if ($end < count($lines)) {
            while ($end > $start + 1 && (trim($lines[$end - 1]) === '' || str_starts_with(trim($lines[$end - 1]), '#'))) {
                $end--;
            }
        } else {
            while ($end > $start + 1 && trim($lines[$end - 1]) === '') {
                $end--;
            }
        }

        $body = implode("\n", array_slice($lines, $start + 1, $end - $start - 1));

        return [
            'start' => $start,
            'end' => $end,
            'command' => self::value($body, 'command'),
            'args' => self::value($body, 'args'),
        ];
    }

    public static function remove(string $contents, int $start, int $end): string
    {
        $lines = explode("\n", $contents);

        array_splice($lines, $start, $end - $start);

        // Collapse the blank lines left where the table was.
        while ($start > 0 && isset($lines[$start]) && trim($lines[$start]) === '' && trim($lines[$start - 1]) === '') {
            array_splice($lines, $start, 1);
        }

        return implode("\n", $lines);
    }

    private static function headerPattern(string $container, string $key): string
    {
        return '/^\s*\[\s*'.preg_quote($container, '/').'\s*\.\s*(?:"'.preg_quote($key, '/').'"|'.preg_quote($key, '/').')\s*\]\s*(?:#.*)?$/';
    }

    private static function subtablePattern(string $container, string $key): string
    {
        return '/^\s*\[\s*'.preg_quote($container, '/').'\s*\.\s*(?:"'.preg_quote($key, '/').'"|'.preg_quote($key, '/').')\s*\./';
    }

    /**
     * Read a string or string-array value from simple `key = value` lines.
     */
    private static function value(string $body, string $key): mixed
    {
        if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=\s*(.+?)\s*(?:#.*)?$/m', $body, $matches) !== 1) {
            return null;
        }

        $raw = trim($matches[1]);

        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $raw, $string) === 1) {
            return stripcslashes($string[1]);
        }

        if (preg_match("/^'([^']*)'$/", $raw, $string) === 1) {
            return $string[1];
        }

        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'/', $raw, $items, PREG_SET_ORDER);

            return array_map(fn (array $item): string => isset($item[2]) && $item[2] !== '' ? $item[2] : stripcslashes($item[1]), $items);
        }

        return $raw;
    }
}
