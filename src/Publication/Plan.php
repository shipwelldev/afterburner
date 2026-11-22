<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

use Shipwell\Afterburner\Configuration\PublishedState;

/**
 * A fully validated set of output changes. Building a plan writes nothing.
 */
final readonly class Plan
{
    /**
     * @param  array<string, string>  $documents  guideline document => new full contents
     * @param  array<string, string|null>  $documentRemovals  document => remaining contents (null deletes the file)
     * @param  array<string, PreparedSkill>  $skills  skill name => prepared skill
     * @param  array<string, array<int, string>>  $skillDestinations  skill directory => names to write
     * @param  array<string, array<int, string>>  $skillRemovals  skill directory => stale names to remove
     * @param  array<int, string>  $guidelineKeys  composed guideline identities, in order
     * @param  array<string, string>  $rules  generated rule file => contents
     * @param  array<int, string>  $ruleRemovals  stale generated rule files
     * @param  bool  $ruleIndexChanges  whether the rules index is rewritten or removed
     * @param  string|null  $ruleIndex  new index contents, or null to remove the index
     * @param  PublishedState|null  $previous  the ownership the plan starts from (publication state plus adopted output)
     */
    public function __construct(
        public array $documents,
        public array $documentRemovals,
        public array $skills,
        public array $skillDestinations,
        public array $skillRemovals,
        public PublishedState $published,
        public array $guidelineKeys = [],
        public array $rules = [],
        public array $ruleRemovals = [],
        public bool $ruleIndexChanges = false,
        public ?string $ruleIndex = null,
        public ?PublishedState $previous = null,
    ) {
        //
    }
}
