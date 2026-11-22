<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Console;

use Illuminate\Console\Command;
use Shipwell\Afterburner\Configuration\Agent;
use Shipwell\Afterburner\Configuration\ConfigurationFile;
use Shipwell\Afterburner\Configuration\InvalidConfiguration;
use Shipwell\Afterburner\Configuration\Preset;
use Shipwell\Afterburner\Installation\AgentDetector;
use Shipwell\Afterburner\Installation\CommandInput;
use Shipwell\Afterburner\Installation\Selection;
use Shipwell\Afterburner\Replacement\BoostInstallation;
use Shipwell\Afterburner\Replacement\ReplacementContext;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class InstallCommand extends Command
{
    use PublishesAssets;
    use ReplacesBoost;

    /** @var string */
    protected $signature = 'afterburner:install
        {--agents= : Comma-separated agents to publish for (claude, codex, cursor, pi, opencode)}
        {--base= : Base preset to use (boost, afterburner, core, none)}
        {--replace-boost : Replace Laravel Boost: import its settings, remove it with Composer, and adopt its output}';

    /** @var string */
    protected $description = 'Configure Afterburner and publish guidelines and skills for the selected agents';

    public function handle(): int
    {
        try {
            $configuration = ConfigurationFile::load($this->laravel->basePath());
        } catch (InvalidConfiguration $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('replace-boost') || $this->offerReplacement()) {
            return $this->replaceBoost($configuration);
        }

        try {
            $selection = $this->resolveSelection($configuration, false);
        } catch (InvalidConfiguration $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->reportSelection($selection, $configuration);

        if (ReplacementContext::exists($this->laravel->basePath())) {
            $this->components->warn('A Laravel Boost replacement is in progress. Run `php artisan afterburner:install --replace-boost` to finish it.');
        }

        return $this->publish($configuration, $selection);
    }

    /**
     * Offer replacement interactively when Boost is present. The default answer is no.
     */
    protected function offerReplacement(): bool
    {
        $basePath = $this->laravel->basePath();

        if (! $this->canPrompt() || ReplacementContext::exists($basePath)) {
            return false;
        }

        if (! BoostInstallation::inspect($basePath)->installed && ! is_file($basePath.'/boost.json')) {
            return false;
        }

        return confirm(
            label: 'Laravel Boost is present. Replace it with Afterburner now?',
            default: false,
            hint: 'This imports its choices, removes laravel/boost with Composer, adopts its output, and removes its project MCP entries.',
        );
    }

    /**
     * Resolve the agents and preset from explicit input, saved choices, and first-run prompts.
     *
     * @throws InvalidConfiguration
     */
    protected function resolveSelection(ConfigurationFile $configuration, bool $replacingBoost): Selection
    {
        $settings = $configuration->settings();

        $agents = CommandInput::agents($this->option('agents'));
        $agentsChosen = $agents !== null;

        if ($agents === null && $settings->agents === null) {
            if (! $this->canPrompt()) {
                throw new InvalidConfiguration(
                    'No agents are selected. Pass --agents (for example --agents=claude,codex) or add an "agents" list to afterburner.json. Supported agents: '.implode(', ', Agent::identifiers()).'. Nothing was changed.'
                );
            }

            $agents = $this->promptForAgents();
            $agentsChosen = true;
        }

        $base = CommandInput::base($this->option('base'));
        $baseChosen = $base !== null;

        if ($base === null && $settings->base === null && $this->canPrompt()) {
            $base = $this->promptForBase(Preset::default($replacingBoost));
            $baseChosen = true;
        }

        return new Selection(
            agents: $agents ?? $settings->agents ?? [],
            base: $base ?? $settings->base ?? Preset::default($replacingBoost),
            agentsChosen: $agentsChosen,
            baseChosen: $baseChosen,
        );
    }

    /**
     * Prompts are only shown to a person: never with --no-interaction or inside a Composer script.
     */
    protected function canPrompt(): bool
    {
        return $this->input->isInteractive() && getenv('COMPOSER_DEV_MODE') === false;
    }

    /**
     * @return array<int, Agent>
     */
    protected function promptForAgents(): array
    {
        $detected = AgentDetector::detect($this->laravel->basePath());

        $selected = multiselect(
            label: 'Which agents should Afterburner publish guidelines and skills for?',
            options: collect(Agent::cases())->mapWithKeys(fn (Agent $agent): array => [$agent->value => $agent->label()])->all(),
            default: array_map(fn (Agent $agent): string => $agent->value, $detected),
            hint: 'Detected agents are pre-selected. Only the agents you choose are written.',
        );

        return Agent::normalize(array_map(fn (int|string $agent): Agent => Agent::from((string) $agent), $selected));
    }

    protected function promptForBase(Preset $default): Preset
    {
        return Preset::from((string) select(
            label: 'Which base preset should Afterburner use?',
            options: collect(Preset::cases())->mapWithKeys(fn (Preset $preset): array => [$preset->value => $preset->label()])->all(),
            default: $default->value,
        ));
    }

    protected function reportSelection(Selection $selection, ConfigurationFile $configuration): void
    {
        $agents = $selection->agents === []
            ? 'none'
            : implode(', ', $selection->agentIdentifiers());

        $settings = $configuration->settings();
        $source = match (true) {
            $selection->baseChosen => 'selected',
            $settings->base !== null => 'saved',
            default => $this->option('replace-boost') ? 'default for Boost replacement' : 'default',
        };

        $this->components->twoColumnDetail('Agents', $agents);
        $this->components->twoColumnDetail('Preset', "{$selection->base->value} ({$source})");
    }
}
