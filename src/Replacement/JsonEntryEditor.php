<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Replacement;

/**
 * Finds and removes one server entry from a JSON or JSONC (comment-bearing)
 * document without reformatting anything else.
 *
 * The document is scanned with a tokenizer that understands strings, line
 * comments, and block comments, so comments and whitespace outside the
 * removed property survive byte-for-byte.
 */
final class JsonEntryEditor
{
    /**
     * Locate `container.key` in the top-level object.
     *
     * @return array{start: int, end: int, value: mixed}|null|false null when absent, false when the syntax is unsupported
     */
    public static function find(string $contents, string $container, string $key): array|false|null
    {
        $root = self::skipTrivia($contents, 0);

        if (($contents[$root] ?? '') !== '{') {
            return false;
        }

        $containerProperty = self::findProperty($contents, $root, $container);

        if ($containerProperty === false) {
            return false;
        }

        if ($containerProperty === null) {
            return null;
        }

        if (($contents[$containerProperty['valueStart']] ?? '') !== '{') {
            return false;
        }

        $entry = self::findProperty($contents, $containerProperty['valueStart'], $key);

        if (! is_array($entry)) {
            return $entry;
        }

        $value = json_decode(self::stripComments(substr($contents, $entry['valueStart'], $entry['valueEnd'] - $entry['valueStart'])), true);

        return ['start' => $entry['start'], 'end' => $entry['end'], 'value' => $value];
    }

    /**
     * Remove a located property and its separating comma, keeping surrounding formatting.
     */
    public static function remove(string $contents, int $start, int $end): string
    {
        $after = self::skipTrivia($contents, $end);
        $ownLine = trim(substr($contents, self::lineStart($contents, $start), $start - self::lineStart($contents, $start))) === '';
        $from = $ownLine ? self::lineStart($contents, $start) : $start;

        if (($contents[$after] ?? '') === ',') {
            $to = $after + 1;

            while (isset($contents[$to]) && ($contents[$to] === ' ' || $contents[$to] === "\t")) {
                $to++;
            }

            if ($ownLine && ($contents[$to] ?? '') === "\n") {
                $to++;
            }

            return substr($contents, 0, $from).substr($contents, $to);
        }

        $before = self::previousSignificant($contents, $start);

        if ($before !== null && $contents[$before] === ',') {
            return substr($contents, 0, $before).substr($contents, $end);
        }

        $to = $end;

        while (isset($contents[$to]) && ($contents[$to] === ' ' || $contents[$to] === "\t")) {
            $to++;
        }

        if ($ownLine && ($contents[$to] ?? '') === "\n") {
            $to++;
        } else {
            $from = $start;
            $to = $end;
        }

        return substr($contents, 0, $from).substr($contents, $to);
    }

    public static function stripComments(string $contents): string
    {
        $result = '';
        $length = strlen($contents);

        for ($i = 0; $i < $length; $i++) {
            $char = $contents[$i];

            if ($char === '"') {
                $end = self::stringEnd($contents, $i);
                $result .= substr($contents, $i, $end - $i);
                $i = $end - 1;
            } elseif ($char === '/' && ($contents[$i + 1] ?? '') === '/') {
                while ($i < $length && $contents[$i] !== "\n") {
                    $i++;
                }

                $result .= "\n";
            } elseif ($char === '/' && ($contents[$i + 1] ?? '') === '*') {
                $close = strpos($contents, '*/', $i + 2);
                $i = $close === false ? $length : $close + 1;
                $result .= ' ';
            } else {
                $result .= $char;
            }
        }

        // JSONC allows trailing commas.
        return (string) preg_replace('/,(\s*[}\]])/', '$1', $result);
    }

    public static function hasComments(string $contents): bool
    {
        return self::stripComments($contents) !== (string) preg_replace('/,(\s*[}\]])/', '$1', $contents);
    }

    /**
     * @return array{start: int, end: int, valueStart: int, valueEnd: int}|null|false
     */
    private static function findProperty(string $contents, int $objectStart, string $name): array|false|null
    {
        $i = self::skipTrivia($contents, $objectStart + 1);

        while (isset($contents[$i]) && $contents[$i] !== '}') {
            if ($contents[$i] !== '"') {
                return false;
            }

            $keyEnd = self::stringEnd($contents, $i);
            $key = json_decode(substr($contents, $i, $keyEnd - $i));
            $colon = self::skipTrivia($contents, $keyEnd);

            if (($contents[$colon] ?? '') !== ':') {
                return false;
            }

            $valueStart = self::skipTrivia($contents, $colon + 1);
            $valueEnd = self::valueEnd($contents, $valueStart);

            if ($valueEnd === false) {
                return false;
            }

            if ($key === $name) {
                return ['start' => $i, 'end' => $valueEnd, 'valueStart' => $valueStart, 'valueEnd' => $valueEnd];
            }

            $i = self::skipTrivia($contents, $valueEnd);

            if (($contents[$i] ?? '') === ',') {
                $i = self::skipTrivia($contents, $i + 1);
            }
        }

        return isset($contents[$i]) ? null : false;
    }

    private static function valueEnd(string $contents, int $start): int|false
    {
        $char = $contents[$start] ?? '';

        if ($char === '"') {
            return self::stringEnd($contents, $start);
        }

        if ($char === '{' || $char === '[') {
            $depth = 0;
            $length = strlen($contents);

            for ($i = $start; $i < $length; $i++) {
                $current = $contents[$i];

                if ($current === '"') {
                    $i = self::stringEnd($contents, $i) - 1;
                } elseif ($current === '/' && in_array($contents[$i + 1] ?? '', ['/', '*'], true)) {
                    $i = self::skipTrivia($contents, $i) - 1;
                } elseif ($current === '{' || $current === '[') {
                    $depth++;
                } elseif ($current === '}' || $current === ']') {
                    $depth--;

                    if ($depth === 0) {
                        return $i + 1;
                    }
                }
            }

            return false;
        }

        if (preg_match('/\G(?:true|false|null|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/', $contents, $matches, 0, $start) === 1) {
            return $start + strlen($matches[0]);
        }

        return false;
    }

    private static function stringEnd(string $contents, int $start): int
    {
        $length = strlen($contents);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($contents[$i] === '\\') {
                $i++;
            } elseif ($contents[$i] === '"') {
                return $i + 1;
            }
        }

        return $length;
    }

    private static function skipTrivia(string $contents, int $offset): int
    {
        $length = strlen($contents);

        while ($offset < $length) {
            if (ctype_space($contents[$offset])) {
                $offset++;
            } elseif ($contents[$offset] === '/' && ($contents[$offset + 1] ?? '') === '/') {
                $newline = strpos($contents, "\n", $offset);
                $offset = $newline === false ? $length : $newline + 1;
            } elseif ($contents[$offset] === '/' && ($contents[$offset + 1] ?? '') === '*') {
                $close = strpos($contents, '*/', $offset + 2);
                $offset = $close === false ? $length : $close + 2;
            } else {
                break;
            }
        }

        return $offset;
    }

    private static function previousSignificant(string $contents, int $before): ?int
    {
        $stripped = self::maskComments(substr($contents, 0, $before));

        for ($i = strlen($stripped) - 1; $i >= 0; $i--) {
            if (! ctype_space($stripped[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Replace comment characters with spaces, keeping offsets stable.
     */
    private static function maskComments(string $contents): string
    {
        $length = strlen($contents);

        for ($i = 0; $i < $length; $i++) {
            if ($contents[$i] === '"') {
                $i = self::stringEnd($contents, $i) - 1;
            } elseif ($contents[$i] === '/' && in_array($contents[$i + 1] ?? '', ['/', '*'], true)) {
                $end = self::skipTrivia($contents, $i);

                for ($j = $i; $j < $end; $j++) {
                    if ($contents[$j] !== "\n") {
                        $contents[$j] = ' ';
                    }
                }

                $i = $end - 1;
            }
        }

        return $contents;
    }

    private static function lineStart(string $contents, int $offset): int
    {
        $newline = strrpos(substr($contents, 0, $offset), "\n");

        return $newline === false ? 0 : $newline + 1;
    }
}
