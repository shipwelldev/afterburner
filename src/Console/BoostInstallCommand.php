<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Console;

/**
 * Compatibility entry point for applications and automation that call the
 * Laravel Boost command name. It runs the same operation as afterburner:install.
 */
class BoostInstallCommand extends InstallCommand
{
    public function __construct()
    {
        $this->signature = (string) preg_replace('/^afterburner:install/', 'boost:install', $this->signature);
        $this->description = 'Compatibility entry point for afterburner:install';

        parent::__construct();
    }
}
