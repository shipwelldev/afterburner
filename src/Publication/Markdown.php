<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

/**
 * Markdown helpers matching Laravel Boost's formatter (MIT, laravel/boost v2.10.4).
 */
final class Markdown
{
    /**
     * Fenced code blocks per CommonMark, so inline backtick runs are not paired.
     */
    private const FENCE = '/^ {0,3}(?<fence>`{3,}|~{3,})[^\n]*\n.*?(?:^ {0,3}\k<fence>[`~]*[ \t]*$|\z)/ms';

    /**
     * Run a transformation over the Markdown outside its fenced code blocks.
     *
     * @param  callable(string): string  $callback
     */
    public static function outsideFences(string $content, callable $callback): string
    {
        $fences = [];

        $masked = preg_replace_callback(self::FENCE, function (array $matches) use (&$fences): string {
            $placeholder = "\0".count($fences)."\0";
            $fences[$placeholder] = $matches[0];

            return $placeholder;
        }, $content);

        if ($masked === null) {
            return $content;
        }

        return str_replace(array_keys($fences), array_values($fences), $callback($masked));
    }

    /**
     * Normalize line endings, space headings, and collapse runs of blank lines outside code fences.
     */
    public static function format(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return self::outsideFences($content, function (string $markdown): string {
            $spaced = preg_replace('/(?<!\n)\n(#{1,4} )/m', "\n\n$1", $markdown);
            $spaced = preg_replace('/^(#{1,4} .+)\n(?!\n)/m', "$1\n\n", (string) $spaced);

            return (string) preg_replace('/\n{3,}/', "\n\n", (string) $spaced);
        });
    }
}
