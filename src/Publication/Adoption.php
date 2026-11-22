<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

use Shipwell\Afterburner\Replacement\BoostCapture;

/**
 * Existing Boost output that an explicit replacement takes ownership of.
 *
 * Adopted guideline documents have their `<laravel-boost-guidelines>` block
 * replaced by (or merged into) the managed Afterburner block; adopted skills
 * and rule files are treated as previously published output, so they are
 * replaced when still selected and removed otherwise.
 */
final readonly class Adoption
{
    /**
     * @param  array<int, string>  $documents
     * @param  array<string, array<int, string>>  $skills
     * @param  array<int, string>  $rules
     */
    public function __construct(
        public array $documents = [],
        public array $skills = [],
        public array $rules = [],
    ) {
        //
    }

    /**
     * Convert a document's Boost block into an Afterburner block, or drop it
     * when the document already has an Afterburner block.
     *
     * @throws PublicationFailed
     */
    public static function convertDocument(string $contents, string $document): string
    {
        $range = BoostCapture::locateBlock($contents, $document);

        if ($range === null) {
            return $contents;
        }

        // Boost separates an appended block from existing text with a "===" line; it belongs to Boost's output.
        $before = substr($contents, 0, $range['start']);

        if (preg_match('/\r?\n\r?\n===(\r?\n\r?\n)$/', $before, $separator) === 1) {
            $contents = substr($before, 0, -strlen('==='.$separator[1])).substr($contents, $range['start']);
            $range = BoostCapture::locateBlock($contents, $document) ?? $range;
        }

        $block = substr($contents, $range['start'], $range['end'] - $range['start']);

        if (ManagedBlock::locate($contents, $document) !== null) {
            $eol = ManagedBlock::lineEnding($contents);
            $before = rtrim(substr($contents, 0, $range['start']));
            $after = ltrim(substr($contents, $range['end']), "\r\n");

            return $before === '' ? ltrim($after) : $before.$eol.$eol.$after;
        }

        $converted = (string) preg_replace(
            ['/^([ \t]*)<laravel-boost-guidelines>/', '/<\/laravel-boost-guidelines>([ \t]*)$/'],
            ['$1'.ManagedBlock::OPEN, ManagedBlock::CLOSE.'$1'],
            $block,
        );

        return substr($contents, 0, $range['start']).$converted.substr($contents, $range['end']);
    }
}
