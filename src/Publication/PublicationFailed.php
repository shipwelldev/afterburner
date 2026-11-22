<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

use RuntimeException;

/**
 * A selected source or output target cannot be published. Raised before any output changes.
 */
class PublicationFailed extends RuntimeException
{
    public static function source(string $source, string $problem): self
    {
        return new self("{$source}: {$problem}");
    }

    public static function target(string $target, string $problem): self
    {
        return new self("{$target}: {$problem}");
    }
}
