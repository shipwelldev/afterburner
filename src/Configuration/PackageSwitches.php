<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Configuration;

/**
 * Independent per-dependency content switches. Omitted switches are enabled.
 */
final readonly class PackageSwitches
{
    public function __construct(
        public bool $guidelines = true,
        public bool $skills = true,
    ) {
        //
    }
}
