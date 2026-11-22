<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Configuration;

/**
 * Generated publication state: the project-relative output Afterburner manages.
 *
 * It records the managed guideline blocks (by document), the skill names
 * published to each skill directory, and generated rule files. It never
 * grants ownership of whole guideline documents or of untracked siblings.
 */
final readonly class PublishedState
{
    /**
     * @param  array<int, string>  $guidelines  documents containing a managed guideline block
     * @param  array<string, array<int, string>>  $skills  skill names keyed by skill directory
     * @param  array<int, string>  $rules  generated rule files
     */
    public function __construct(
        public array $guidelines = [],
        public array $skills = [],
        public array $rules = [],
    ) {
        //
    }

    /**
     * @return array<int, string>
     */
    public function skillsIn(string $directory): array
    {
        return $this->skills[$directory] ?? [];
    }

    public function isEmpty(): bool
    {
        return $this->guidelines === []
            && $this->rules === []
            && array_filter($this->skills, fn (array $names): bool => $names !== []) === [];
    }

    /**
     * @return array{guidelines: array<int, string>, skills: object, rules: array<int, string>}
     */
    public function toJson(): array
    {
        $skills = [];

        foreach ($this->skills as $directory => $names) {
            if ($names !== []) {
                $skills[$directory] = array_values($names);
            }
        }

        ksort($skills);

        return [
            'guidelines' => array_values($this->guidelines),
            'skills' => (object) $skills,
            'rules' => array_values($this->rules),
        ];
    }
}
