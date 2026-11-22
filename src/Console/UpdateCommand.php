<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Console;

use Illuminate\Console\Command;
use Shipwell\Afterburner\Configuration\ConfigurationFile;
use Shipwell\Afterburner\Configuration\InvalidConfiguration;
use Shipwell\Afterburner\Configuration\Preset;
use Shipwell\Afterburner\Installation\Selection;
use Shipwell\Afterburner\Replacement\BoostInstallation;
use Shipwell\Afterburner\Replacement\ReplacementContext;

class UpdateCommand extends Command
{
    use PublishesAssets;
    use ReplacesBoost;

    /** @var string */
    protected $signature = 'afterburner:update';

    /** @var string */
    protected $description = 'Refresh published guidelines and skills using the saved Afterburner choices';

    public function handle(): int
    {
        try {
            $configuration = ConfigurationFile::load($this->laravel->basePath());
        } catch (InvalidConfiguration $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $basePath = $this->laravel->basePath();

        if (ReplacementContext::exists($basePath)) {
            return $this->refreshDuringReplacement($configuration, $basePath);
        }

        $settings = $configuration->settings();

        if ($settings->agents === null) {
            $this->components->error('No agents are saved in afterburner.json. Run `php artisan afterburner:install` (or pass --agents to it) first. Nothing was changed.');

            return self::FAILURE;
        }

        return $this->publish($configuration, new Selection(
            agents: $settings->agents,
            base: $settings->base ?? Preset::default(),
            agentsChosen: false,
            baseChosen: false,
        ));
    }

    /**
     * An update that runs while a Boost replacement is in progress (for example
     * from a Composer callback during `composer remove laravel/boost`) uses the
     * replacement's recorded choices and never reports the replacement as done.
     */
    protected function refreshDuringReplacement(ConfigurationFile $configuration, string $basePath): int
    {
        if (BoostInstallation::inspect($basePath)->installed) {
            $this->components->warn('A Laravel Boost replacement is in progress and Boost is still installed; skipping this refresh.');

            return self::SUCCESS;
        }

        try {
            $context = ReplacementContext::load($basePath);
        } catch (\RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return $this->finishReplacement($configuration, $context, publishOnly: true);
    }

    /**
     * Updates never prompt.
     */
    protected function canPrompt(): bool
    {
        return false;
    }

    protected function promptForBase(Preset $default): Preset
    {
        return $default;
    }
}
