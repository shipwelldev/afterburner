<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

/**
 * A validated skill ready to be written: its identity and the files of its published folder.
 */
final readonly class PreparedSkill
{
    /**
     * @param  array<string, array{contents: string, mode: int|null}>  $files  relative path => contents
     */
    public function __construct(
        public string $name,
        public string $description,
        public SkillSource $source,
        public array $files,
    ) {
        //
    }
}
