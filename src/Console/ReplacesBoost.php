<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Console;

use RuntimeException;
use Shipwell\Afterburner\Configuration\Agent;
use Shipwell\Afterburner\Configuration\ConfigurationFile;
use Shipwell\Afterburner\Configuration\InvalidConfiguration;
use Shipwell\Afterburner\Configuration\Preset;
use Shipwell\Afterburner\Discovery\PackageInventory;
use Shipwell\Afterburner\Installation\AgentDetector;
use Shipwell\Afterburner\Installation\CommandInput;
use Shipwell\Afterburner\Installation\Selection;
use Shipwell\Afterburner\Publication\Adoption;
use Shipwell\Afterburner\Publication\Planner;
use Shipwell\Afterburner\Publication\PublicationFailed;
use Shipwell\Afterburner\Replacement\BoostCapture;
use Shipwell\Afterburner\Replacement\BoostInstallation;
use Shipwell\Afterburner\Replacement\ComposerRunner;
use Shipwell\Afterburner\Replacement\McpCleanup;
use Shipwell\Afterburner\Replacement\ReplacementContext;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\multiselect;

/**
 * The explicit Boost replacement operation behind `afterburner:install --replace-boost`.
 *
 * Phases: capture Boost's choices and output while it is installed, resolve
 * and validate the selection, record the operation context, remove Boost
 * with Composer, then finish in a fresh application process: publish with
 * adoption of Boost's output, remove Boost's project-local MCP entries, save
 * the choices, and clear the context. A repeat run after a failure resumes
 * from the recorded context instead of removing Boost again.
 */
trait ReplacesBoost
{
    protected function replaceBoost(ConfigurationFile $configuration): int
    {
        $basePath = $this->laravel->basePath();
        $installation = BoostInstallation::inspect($basePath);

        try {
            $pending = ReplacementContext::load($basePath);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($pending !== null && ! $installation->installed) {
            $this->components->info('Finishing the Laravel Boost replacement recorded on '.$pending->startedAt.'.');

            return $this->finishReplacement($configuration, $pending, publishOnly: false);
        }

        try {
            $captured = BoostCapture::capture($basePath, (array) config('boost', []));
            $context = $this->resolveReplacement($configuration, $captured);
            $this->preflightReplacement($configuration, $context);
        } catch (InvalidConfiguration|PublicationFailed $exception) {
            $this->components->error($exception->getMessage());
            $this->components->error('The replacement stopped before changing anything; Laravel Boost is still '.($installation->installed ? 'installed' : 'in place').'.');

            return self::FAILURE;
        }

        $this->reportCapture($captured, $context, $installation);

        if (! $installation->installed) {
            $this->components->info('Laravel Boost is not installed, so no Composer change is needed.');
            $context->save($basePath);

            return $this->finishReplacement($configuration, $context, publishOnly: false);
        }

        try {
            $composer = ComposerRunner::locate();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $context->save($basePath);

        $this->components->info('Removing laravel/boost with Composer ('.$composer->describe().').');

        $exitCode = $composer->remove(BoostInstallation::PACKAGE, $installation->requirement, $basePath, function (string $type, string $buffer): void {
            $this->errorOutput($type === Process::ERR)->write($buffer);
        });

        if (BoostInstallation::inspect($basePath)->installed) {
            ReplacementContext::delete($basePath);

            $this->components->error("Composer did not remove laravel/boost (exit code {$exitCode}); it is still installed. If another package requires it, Composer's output above says so and `composer why laravel/boost` shows which one.");
            $this->components->error('The replacement stopped. Afterburner did not publish anything or edit MCP configuration. Composer may already have changed composer.json or composer.lock; review those changes before trying again.');

            return self::FAILURE;
        }

        if ($exitCode !== 0) {
            $this->components->error("Composer removed laravel/boost but exited with code {$exitCode} (for example, a script failed). Run `php artisan afterburner:install --replace-boost` again to finish the replacement.");

            return self::FAILURE;
        }

        return $this->finishInFreshProcess($basePath);
    }

    /**
     * Resolve the agents and preset for the replacement. Fails before any change when agents cannot be resolved.
     *
     * @throws InvalidConfiguration|PublicationFailed
     */
    protected function resolveReplacement(ConfigurationFile $configuration, BoostCapture $captured): ReplacementContext
    {
        $settings = $configuration->settings();

        $agents = CommandInput::agents($this->option('agents')) ?? $settings->agents;

        if ($agents === null && $captured->agents !== []) {
            $agents = $captured->agents;
        }

        if ($agents === null) {
            if (! $this->canPrompt()) {
                throw new InvalidConfiguration(
                    'No agents could be resolved for the replacement'.($captured->unsupportedAgents !== [] ? ' (Boost was configured only for unsupported agents: '.implode(', ', $captured->unsupportedAgents).')' : '').'. Pass --agents (for example --agents=claude,codex) or add an "agents" list to afterburner.json.'
                );
            }

            $agents = $this->promptForReplacementAgents();
        }

        $base = CommandInput::base($this->option('base')) ?? $settings->base;

        if ($base === null && $this->canPrompt()) {
            $base = $this->promptForBase(Preset::default(replacingBoost: true));
        }

        return new ReplacementContext(
            agents: Agent::normalize($agents),
            base: $base ?? Preset::default(replacingBoost: true),
            preferences: $captured->preferences,
            adoption: new Adoption($captured->documents, $captured->skills, $captured->rules),
            mcpLocations: $captured->mcpLocations,
            notes: $captured->notes,
            startedAt: gmdate('Y-m-d H:i:s').' UTC',
        );
    }

    /**
     * Validate the complete selection, including adoption of Boost's output, before changing anything.
     *
     * @throws PublicationFailed
     */
    protected function preflightReplacement(ConfigurationFile $configuration, ReplacementContext $context): void
    {
        $basePath = $this->laravel->basePath();
        $preview = ConfigurationFile::load($basePath);
        $this->applyImportedPreferences($preview, $context);

        $settings = $preview->settings();
        $selection = $this->replacementSelection($context);
        $inventory = PackageInventory::scan();

        (new Planner($basePath, $this->renderer($basePath, $settings, $inventory)))
            ->plan($settings, $selection, $this->catalog($basePath, $settings, $selection, $inventory), $context->adoption);
    }

    /**
     * Publish with the recorded choices and adoption; unless only refreshing, also clean up MCP entries and finish.
     */
    protected function finishReplacement(ConfigurationFile $configuration, ReplacementContext $context, bool $publishOnly): int
    {
        $basePath = $this->laravel->basePath();
        $this->applyImportedPreferences($configuration, $context);

        $result = $this->publish($configuration, $this->replacementSelection($context), $context->adoption);

        if ($result !== self::SUCCESS) {
            $this->components->error('Laravel Boost has been removed, but publishing failed. Fix the problem above and run `php artisan afterburner:install --replace-boost` again to finish; the recorded choices are kept in '.ReplacementContext::FILE.'.');

            return $result;
        }

        if ($publishOnly) {
            $this->components->warn('A Laravel Boost replacement is in progress; this refresh used the choices it recorded. `php artisan afterburner:install --replace-boost` finishes it.');

            return self::SUCCESS;
        }

        $findings = (new McpCleanup($basePath, $context->mcpLocations))->apply();
        $failed = array_filter($findings, fn (array $finding): bool => $finding['status'] === 'failed');

        foreach ($findings as $finding) {
            match ($finding['status']) {
                'removed' => $this->components->twoColumnDetail('MCP '.$finding['file'], $finding['detail']),
                default => $this->components->warn("{$finding['file']}: {$finding['detail']}."),
            };
        }

        foreach ($context->notes as $note) {
            $this->components->warn($note);
        }

        $this->components->warn('Afterburner does not edit global agent settings. If you added the laravel-boost MCP server globally (for example in ~/.cursor/mcp.json, ~/.codex/config.toml, or with `claude mcp add --scope user`), remove it there yourself.');

        if ($failed !== []) {
            $this->components->error('The replacement could not finish cleaning up MCP configuration. Fix the files above and run `php artisan afterburner:install --replace-boost` again.');

            return self::FAILURE;
        }

        ReplacementContext::delete($basePath);

        if (is_file($basePath.'/boost.json') || is_file($basePath.'/config/boost.php')) {
            $this->components->info('boost.json and config/boost.php are no longer used by any installed package; delete them when you no longer need them.');
        }

        $this->components->info('Laravel Boost has been replaced by Afterburner.');

        return self::SUCCESS;
    }

    protected function finishInFreshProcess(string $basePath): int
    {
        if (! is_file($basePath.'/artisan')) {
            $this->components->error('Laravel Boost was removed. Run `php artisan afterburner:install --replace-boost` to finish the replacement in a fresh process.');

            return self::FAILURE;
        }

        $this->components->info('Laravel Boost was removed. Finishing the replacement in a fresh application process.');

        $process = new Process(
            [PHP_BINARY, $basePath.'/artisan', 'afterburner:install', '--replace-boost', '--no-interaction', $this->output->isDecorated() ? '--ansi' : '--no-ansi'],
            $basePath,
            null,
            null,
            null,
        );

        $process->run(function (string $type, string $buffer): void {
            $this->errorOutput($type === Process::ERR)->write($buffer);
        });

        return $process->getExitCode() === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function applyImportedPreferences(ConfigurationFile $configuration, ReplacementContext $context): void
    {
        $configuration
            ->importPreference('integrations.sail', $context->preferences['sail'])
            ->importPreference('integrations.cloud', $context->preferences['cloud'])
            ->importPreference('enforce_tests', $context->preferences['enforce_tests'])
            ->importPreference('rules.scoped_guidelines', $context->preferences['scoped_guidelines'])
            ->importPreference('purpose', $context->preferences['purpose'] ?? null);
    }

    protected function replacementSelection(ReplacementContext $context): Selection
    {
        return new Selection(agents: $context->agents, base: $context->base, agentsChosen: true, baseChosen: true);
    }

    protected function reportCapture(BoostCapture $captured, ReplacementContext $context, BoostInstallation $installation): void
    {
        $this->components->twoColumnDetail('Laravel Boost', $installation->installed ? 'installed ('.($installation->requirement ?? 'not a root requirement').')' : 'not installed');
        $this->components->twoColumnDetail('Agents', implode(', ', array_map(fn (Agent $agent): string => $agent->value, $context->agents)) ?: 'none');
        $this->components->twoColumnDetail('Preset', $context->base->value);
        $this->components->twoColumnDetail('Boost guideline blocks', implode(', ', $captured->documents) ?: 'none');
        $this->components->twoColumnDetail('Boost skills adopted', (string) array_sum(array_map('count', $captured->skills)));

        $findings = (new McpCleanup($this->laravel->basePath(), $captured->mcpLocations))->inspect();

        $this->components->twoColumnDetail('Boost MCP entries', implode(', ', array_map(fn (array $finding): string => $finding['file'], $findings)) ?: 'none');
    }

    /**
     * @return array<int, Agent>
     */
    protected function promptForReplacementAgents(): array
    {
        $selected = multiselect(
            label: 'Which agents should Afterburner publish guidelines and skills for?',
            options: collect(Agent::cases())->mapWithKeys(fn (Agent $agent): array => [$agent->value => $agent->label()])->all(),
            default: array_map(fn (Agent $agent): string => $agent->value, AgentDetector::detect($this->laravel->basePath())),
        );

        return Agent::normalize(array_map(fn (int|string $agent): Agent => Agent::from((string) $agent), $selected));
    }

    protected function errorOutput(bool $error): OutputInterface
    {
        $output = $this->output->getOutput();

        return $error && $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
