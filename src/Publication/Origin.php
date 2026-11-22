<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

/**
 * Where a guideline or skill source comes from.
 */
enum Origin: string
{
    case Bundled = 'bundled';
    case Dependency = 'dependency';
    case Application = 'application';
}
