<?php

declare(strict_types=1);

namespace Shipwell\Afterburner;

use Illuminate\Support\ServiceProvider;
use Shipwell\Afterburner\Console\BoostIndexRulesCommand;
use Shipwell\Afterburner\Console\BoostInstallCommand;
use Shipwell\Afterburner\Console\BoostUpdateCommand;
use Shipwell\Afterburner\Console\DatabaseQueryCommand;
use Shipwell\Afterburner\Console\IndexRulesCommand;
use Shipwell\Afterburner\Console\InstallCommand;
use Shipwell\Afterburner\Console\UpdateCommand;

/**
 * Registers Afterburner's console commands.
 *
 * Booting the provider has no other effect: it publishes nothing, reads or
 * writes no configuration, starts no process or server, and registers no
 * routes or middleware. All work happens through the public commands.
 */
class AfterburnerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
            UpdateCommand::class,
            IndexRulesCommand::class,
            DatabaseQueryCommand::class,
        ]);

        // While Laravel Boost is still installed its own boost:* commands stay in charge;
        // use afterburner:install --replace-boost to switch. Afterwards these names
        // run the corresponding Afterburner operations.
        if (! class_exists('Laravel\\Boost\\BoostServiceProvider')) {
            $this->commands([
                BoostInstallCommand::class,
                BoostUpdateCommand::class,
                BoostIndexRulesCommand::class,
            ]);
        }
    }
}
