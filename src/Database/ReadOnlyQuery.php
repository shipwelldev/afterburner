<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Database;

/**
 * Structural validation and table-prefix rewriting for read-only SQL.
 *
 * The rules follow Laravel Boost v2.10.4's database-query tool (MIT). They
 * are a fast-fail guard; the command also runs every query inside a
 * driver-enforced read-only transaction. Comments and quoted text are read
 * the way the connection's engine reads them, and a query is allowed only
 * when it passes under every reading the engine's server modes permit.
 */
final class ReadOnlyQuery
{
    /**
     * Statement-starting keywords that write data.
     */
    private const WRITE_KEYWORDS = 'DELETE|UPDATE|DROP|ALTER|TRUNCATE|RENAME|CREATE|MERGE';

    /**
     * First keywords of the permitted read-only statement forms.
     */
    private const ALLOWED = ['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC', 'WITH', 'VALUES', 'TABLE'];

    /**
     * Explain why a query is not read-only on the driver, or return null when it is allowed.
     */
    public static function rejection(string $query, string $driver): ?string
    {
        foreach (SqlDialect::readings($driver) as $dialect) {
            $rejection = self::rejectionOf(self::scan($query, $dialect));

            if ($rejection !== null) {
                return $rejection;
            }
        }

        return null;
    }

    /**
     * @param  array{structure: string, hasExecutableComment: bool, spans: list<array{int, int}>}  $scan
     */
    private static function rejectionOf(array $scan): ?string
    {
        ['structure' => $structure, 'hasExecutableComment' => $hasExecutableComment] = $scan;

        if ($hasExecutableComment) {
            return 'MySQL and MariaDB executable comments (/*! … */, /*M! … */) are not allowed.';
        }

        if (preg_match('/;\s*\S/', $structure) === 1) {
            return 'Only a single statement is allowed.';
        }

        $token = strtok($structure, " \t\n\r(");

        if ($token === false) {
            return 'The query is empty.';
        }

        $first = strtoupper($token);

        if (! in_array($first, self::ALLOWED, true)) {
            return 'Only read-only queries are allowed (SELECT, SHOW, EXPLAIN, DESCRIBE, DESC, WITH … SELECT, VALUES, TABLE).';
        }

        if ($first === 'WITH' && preg_match('/\)\s*SELECT\b/i', $structure) !== 1) {
            return 'A WITH query must end in a SELECT.';
        }

        // Write keywords at any statement boundary (^, after "(", ")" or ";"). REPLACE()/INSERT() function calls stay allowed.
        if (preg_match('/(^|[();])\s*(?:(?:'.self::WRITE_KEYWORDS.')\b|(?:INSERT|REPLACE)\b(?!\s*\())/i', $structure) === 1) {
            return 'The query contains a data-modifying statement.';
        }

        // EXPLAIN ANALYZE executes the statement it explains, so EXPLAIN may only target reads.
        if ($first === 'EXPLAIN' && preg_match('/^\s*EXPLAIN\s+(?:\([^)]*\)\s*|(?:ANALYZE|VERBOSE|QUERY\s+PLAN|FORMAT\s*=?\s*\w+)\s+)*(?:'.self::WRITE_KEYWORDS.'|INSERT|REPLACE)\b/i', $structure) === 1) {
            return 'EXPLAIN may only target read-only statements.';
        }

        if (preg_match('/\bINTO\b/i', $structure) === 1) {
            return 'INTO writes data (SELECT … INTO, INTO OUTFILE, INTO DUMPFILE) and is not allowed.';
        }

        return null;
    }

    /**
     * Add a connection's table prefix to table references outside literals and comments.
     */
    public static function prefixed(string $query, string $prefix, string $driver): string
    {
        // Anchored to the start so an ORDER BY … DESC sort direction is never matched.
        $query = preg_replace_callback(
            '/^(\s*)(DESCRIBE|DESC)\s+((?:[`"]?\w+[`"]?\s*\.\s*)?)([`"\']?)(\w+)\4/i',
            function (array $matches) use ($prefix): string {
                [$full, $leading, $keyword, $qualifier, $quote, $table] = $matches;

                return str_starts_with($table, $prefix) ? $full : "{$leading}{$keyword} {$qualifier}{$quote}{$prefix}{$table}{$quote}";
            },
            $query,
        ) ?? $query;

        ['structure' => $structure, 'spans' => $spans] = self::scan($query, SqlDialect::readings($driver)[0]);
        $cteNames = self::cteNames($structure);

        if (! preg_match_all('/\b(FROM|JOIN|INTO|UPDATE|TABLE)\s+((?:[`"]?\w+[`"]?\s*\.\s*)?)([`"\']?)(\w+)\3/i', $query, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $query;
        }

        // Rewriting right to left keeps the offsets of earlier matches valid.
        foreach (array_reverse($all) as $matches) {
            [$full, $offset] = $matches[0];

            if (self::insideSpan($offset, $spans)) {
                continue;
            }

            $table = $matches[4][0];

            if (str_starts_with($table, $prefix) || in_array(strtolower($table), $cteNames, true)) {
                continue;
            }

            $query = substr_replace($query, "{$matches[1][0]} {$matches[2][0]}{$matches[3][0]}{$prefix}{$table}{$matches[3][0]}", $offset, strlen($full));
        }

        return $query;
    }

    /**
     * Blank out quoted text and comments as the dialect reads them, recording their byte ranges.
     *
     * A line comment's newline stays in the structure. Unterminated quoted text
     * or comments run to the end of the query, which the engine rejects.
     *
     * @return array{structure: string, hasExecutableComment: bool, spans: list<array{int, int}>}
     */
    private static function scan(string $query, SqlDialect $dialect): array
    {
        $structure = '';
        $hasExecutableComment = false;
        $spans = [];
        $length = strlen($query);
        $i = 0;

        while ($i < $length) {
            $end = self::lineCommentEnd($query, $i, $dialect)
                ?? self::blockCommentEnd($query, $i, $dialect)
                ?? self::quotedEnd($query, $i, $dialect);

            if ($end === null) {
                $structure .= $query[$i];
                $i++;

                continue;
            }

            if (str_starts_with(substr($query, $i, 4), '/*!') || strtoupper(substr($query, $i, 4)) === '/*M!') {
                $hasExecutableComment = true;
            }

            $spans[] = [$i, $end];
            $structure .= ' ';
            $i = $end;
        }

        return ['structure' => $structure, 'hasExecutableComment' => $hasExecutableComment, 'spans' => $spans];
    }

    private static function lineCommentEnd(string $query, int $i, SqlDialect $dialect): ?int
    {
        $isDashComment = substr($query, $i, 2) === '--'
            && (! $dialect->dashCommentNeedsSpace || ! isset($query[$i + 2]) || ord($query[$i + 2]) <= 32 || ord($query[$i + 2]) === 127);

        if (! $isDashComment && ! ($dialect->hashComments && $query[$i] === '#')) {
            return null;
        }

        $newline = strpos($query, "\n", $i);

        return $newline === false ? strlen($query) : $newline;
    }

    private static function blockCommentEnd(string $query, int $i, SqlDialect $dialect): ?int
    {
        if (substr($query, $i, 2) !== '/*') {
            return null;
        }

        $length = strlen($query);
        $depth = 1;

        for ($j = $i + 2; $j < $length - 1; $j++) {
            $pair = $query[$j].$query[$j + 1];

            if ($pair === '*/') {
                $depth--;
                $j++;

                if ($depth === 0 || ! $dialect->nestedBlockComments) {
                    return $j + 1;
                }
            } elseif ($pair === '/*' && $dialect->nestedBlockComments) {
                $depth++;
                $j++;
            }
        }

        return $length;
    }

    private static function quotedEnd(string $query, int $i, SqlDialect $dialect): ?int
    {
        $char = $query[$i];
        $startsToken = fn (): bool => $i === 0 || preg_match('/[A-Za-z0-9_$\x80-\xff]/', $query[$i - 1]) !== 1;

        if ($dialect->postgresStrings && $char === '$' && $startsToken() && preg_match('/\G\$(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/', $query, $tag, 0, $i) === 1) {
            $close = strpos($query, $tag[0], $i + strlen($tag[0]));

            return $close === false ? strlen($query) : $close + strlen($tag[0]);
        }

        if ($dialect->postgresStrings && ($char === 'E' || $char === 'e') && ($query[$i + 1] ?? '') === "'" && $startsToken()) {
            return self::closingQuote($query, $i + 1, "'", true);
        }

        if ($dialect->bracketIdentifiers && $char === '[') {
            $close = strpos($query, ']', $i + 1);

            return $close === false ? strlen($query) : $close + 1;
        }

        if (! str_contains($dialect->quotes, $char)) {
            return null;
        }

        $backslashEscapes = match ($char) {
            "'" => $dialect->singleQuoteBackslash,
            '"' => $dialect->doubleQuoteBackslash,
            default => false,
        };

        return self::closingQuote($query, $i, $char, $backslashEscapes);
    }

    /**
     * The end of quoted text opened at $open, where a doubled quote character is an escaped quote.
     */
    private static function closingQuote(string $query, int $open, string $quote, bool $backslashEscapes): int
    {
        $length = strlen($query);

        for ($j = $open + 1; $j < $length; $j++) {
            if ($backslashEscapes && $query[$j] === '\\') {
                $j++;
            } elseif ($query[$j] === $quote) {
                if (($query[$j + 1] ?? '') !== $quote) {
                    return $j + 1;
                }

                $j++;
            }
        }

        return $length;
    }

    /**
     * @param  list<array{int, int}>  $spans
     */
    private static function insideSpan(int $offset, array $spans): bool
    {
        foreach ($spans as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private static function cteNames(string $query): array
    {
        return preg_match_all('/\b(\w+)\s*(?:\([^)]*\))?\s*AS\s*\(/i', $query, $matches)
            ? array_map(strtolower(...), $matches[1])
            : [];
    }
}
