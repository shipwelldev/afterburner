<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\CommandResult;
use Tests\Support\TemporaryDirectory;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/**
 * Afterburner's core skills (the replacements for Boost's MCP tools), in published (alphabetical) order.
 */
const CORE_SKILLS = [
    'afterburner-application-inspection',
    'afterburner-boost-tool-compatibility',
    'afterburner-debugging',
    'afterburner-documentation',
    'afterburner-project-rules',
];

pest()->group('process')->in('Process');

pest()->group('composer')->in('Process/RealComposerReplacementTest.php');

/**
 * Rebuild the pristine Laravel Boost release in a temporary directory by
 * reverse-applying ADAPTATIONS.patch to the shipped snapshot. The caller removes it.
 */
function pristineBoostSnapshot(): string
{
    $snapshot = dirname(__DIR__).'/resources/boost';
    $directory = TemporaryDirectory::make('afterburner-pristine-boost-');

    (new Process(['cp', '-a', $snapshot.'/.', $directory]))->mustRun();

    foreach (['LICENSE.md', 'PROVENANCE.json', 'ADAPTATIONS.patch'] as $metadata) {
        @unlink($directory.'/'.$metadata);
    }

    (new Process(['git', 'apply', '-R', '-p1', $snapshot.'/ADAPTATIONS.patch'], $directory))->mustRun();

    return $directory;
}

/**
 * Run a command noninteractively and capture its exit code and full output.
 *
 * @param  array<string, mixed>  $parameters
 */
function runCommand(string $command, array $parameters = []): CommandResult
{
    return CommandResult::run($command, $parameters);
}
