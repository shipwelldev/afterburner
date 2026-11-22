<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

/**
 * Agent-readable content produced from a source file.
 */
final readonly class Rendered
{
    /**
     * @param  string  $content  the rendered text, with any `@scoped` bodies inline
     * @param  array<int, array{paths: array<int, string>, body: string}>  $scoped  recorded `@scoped` blocks
     * @param  string|null  $marked  the rendered text with recorded blocks wrapped in removable markers
     */
    public function __construct(
        public string $content,
        public array $scoped = [],
        public ?string $marked = null,
    ) {
        //
    }

    public static function scopedMarker(int $index): string
    {
        return "\0SCOPED{$index}\0";
    }

    /**
     * The rendered text with every recorded `@scoped` block removed.
     */
    public function withoutScoped(): string
    {
        if ($this->marked === null || $this->scoped === []) {
            return $this->content;
        }

        return (string) preg_replace('/\x00SCOPED(\d+)\x00.*?\x00SCOPED\1\x00/s', '', $this->marked);
    }
}
