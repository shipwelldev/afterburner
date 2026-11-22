<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Console;

/**
 * Compatibility entry point for applications and automation that call the
 * Laravel Boost command name. It runs the same operation as afterburner:index-rules.
 */
class BoostIndexRulesCommand extends IndexRulesCommand
{
    public function __construct()
    {
        $this->signature = (string) preg_replace('/^afterburner:index-rules/', 'boost:index-rules', $this->signature);
        $this->description = 'Compatibility entry point for afterburner:index-rules';

        parent::__construct();
    }
}
