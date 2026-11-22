<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

/**
 * Locates and rewrites the Afterburner-managed section of a guideline document.
 *
 * Delimiters only count when they occupy a whole line, so prose or code that
 * mentions the tag inline is never mistaken for managed content. Lines may end
 * in LF or CRLF, and a written block uses the document's line endings.
 */
final class ManagedBlock
{
    public const OPEN = '<afterburner-guidelines>';

    public const CLOSE = '</afterburner-guidelines>';

    private const OPEN_LINE = '/^[ \t]*<afterburner-guidelines>[ \t]*(?=\r?$)/m';

    private const CLOSE_LINE = '/^[ \t]*<\/afterburner-guidelines>[ \t]*(?=\r?$)/m';

    /**
     * Find the managed block's byte range, or null when the document has none.
     *
     * @return array{start: int, end: int}|null
     *
     * @throws PublicationFailed when the delimiters are malformed or ambiguous
     */
    public static function locate(string $contents, string $document): ?array
    {
        preg_match_all(self::OPEN_LINE, $contents, $opens, PREG_OFFSET_CAPTURE);
        preg_match_all(self::CLOSE_LINE, $contents, $closes, PREG_OFFSET_CAPTURE);

        $openCount = count($opens[0]);
        $closeCount = count($closes[0]);

        if ($openCount === 0 && $closeCount === 0) {
            return null;
        }

        if ($openCount !== 1 || $closeCount !== 1 || $closes[0][0][1] < $opens[0][0][1]) {
            throw PublicationFailed::target(
                $document,
                "the Afterburner guideline delimiters are malformed ({$openCount} opening and {$closeCount} closing lines). Repair or remove the ".self::OPEN.' section by hand so no manual text is lost',
            );
        }

        return [
            'start' => $opens[0][0][1],
            'end' => $closes[0][0][1] + strlen($closes[0][0][0]),
        ];
    }

    public static function wrap(string $body, string $lineEnding = "\n"): string
    {
        $block = self::OPEN."\n".trim($body)."\n\n".self::CLOSE;

        return $lineEnding === "\n" ? $block : (string) preg_replace('/\r?\n/', $lineEnding, $block);
    }

    /**
     * The document's line ending: CRLF when it has any, otherwise LF.
     */
    public static function lineEnding(string $contents): string
    {
        return str_contains($contents, "\r\n") ? "\r\n" : "\n";
    }

    /**
     * Whether text would be mistaken for a delimiter if placed inside the block.
     */
    public static function containsDelimiter(string $text): bool
    {
        return preg_match(self::OPEN_LINE, $text) === 1 || preg_match(self::CLOSE_LINE, $text) === 1;
    }

    /**
     * Replace the managed block in place, or append one, preserving all other text.
     *
     * @throws PublicationFailed
     */
    public static function put(string $contents, string $body, string $document): string
    {
        $eol = self::lineEnding($contents);
        $block = self::wrap($body, $eol);
        $range = self::locate($contents, $document);

        if ($range !== null) {
            return substr($contents, 0, $range['start']).$block.substr($contents, $range['end']);
        }

        $existing = rtrim($contents);

        return ($existing === '' ? '' : $existing.$eol.$eol).$block.$eol;
    }

    /**
     * Remove the managed block, preserving all other text.
     *
     * @throws PublicationFailed
     */
    public static function remove(string $contents, string $document): string
    {
        $range = self::locate($contents, $document);

        if ($range === null) {
            return $contents;
        }

        $eol = self::lineEnding($contents);
        $before = rtrim(substr($contents, 0, $range['start']));
        $after = ltrim(substr($contents, $range['end']), "\r\n");

        if ($before === '') {
            return ltrim($after);
        }

        return trim($after) === '' ? $before.$eol : $before.$eol.$eol.$after;
    }
}
