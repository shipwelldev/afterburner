<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Installation;

use Shipwell\Afterburner\Configuration\Agent;
use Shipwell\Afterburner\Configuration\Preset;

/**
 * The effective agent and preset choices for one command invocation.
 */
final readonly class Selection
{
    /**
     * @param  array<int, Agent>  $agents
     */
    public function __construct(
        public array $agents,
        public Preset $base,
        public bool $agentsChosen,
        public bool $baseChosen,
    ) {
        //
    }

    /**
     * @return array<int, string>
     */
    public function agentIdentifiers(): array
    {
        return array_map(fn (Agent $agent): string => $agent->value, $this->agents);
    }
}
