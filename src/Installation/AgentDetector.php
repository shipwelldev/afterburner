<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Installation;

use Shipwell\Afterburner\Configuration\Agent;

/**
 * Suggests agents from project-local markers.
 *
 * Detection only pre-selects interactive choices. It never inspects global
 * installations and never authorizes writing an agent's files.
 */
final class AgentDetector
{
    /**
     * @return array<int, Agent>
     */
    public static function detect(string $basePath): array
    {
        return array_values(array_filter(
            Agent::cases(),
            function (Agent $agent) use ($basePath): bool {
                foreach ($agent->projectMarkers() as $marker) {
                    if (file_exists($basePath.DIRECTORY_SEPARATOR.$marker)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }
}
