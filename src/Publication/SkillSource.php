<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

/**
 * A candidate skill folder. Its identity is the frontmatter name of its entry point.
 */
final readonly class SkillSource
{
    public function __construct(
        public string $directory,
        public string $entry,
        public Origin $origin,
        public ?string $package = null,
    ) {
        //
    }

    /**
     * Find a skill folder's entry point, preferring a Blade template over Markdown.
     */
    public static function in(string $directory, Origin $origin, ?string $package = null): ?self
    {
        foreach (['SKILL.blade.php', 'SKILL.md'] as $entry) {
            if (is_file($directory.DIRECTORY_SEPARATOR.$entry)) {
                return new self($directory, $entry, $origin, $package);
            }
        }

        return null;
    }

    public function entryPath(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$this->entry;
    }

    public function isBlade(): bool
    {
        return $this->entry === 'SKILL.blade.php';
    }
}
