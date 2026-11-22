<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Console;

/**
 * Compatibility entry point for applications and automation that call the
 * Laravel Boost command name. It runs the same operation as afterburner:update.
 */
class BoostUpdateCommand extends UpdateCommand
{
    public function __construct()
    {
        $this->signature = (string) preg_replace('/^afterburner:update/', 'boost:update', $this->signature);
        $this->description = 'Compatibility entry point for afterburner:update';

        parent::__construct();
    }
}
