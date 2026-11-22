<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

use RuntimeException;

/**
 * A filesystem operation failed while applying an already validated plan.
 */
class WriteFailed extends RuntimeException
{
    public static function operation(string $operation, string $target): self
    {
        $error = error_get_last()['message'] ?? null;

        return new self("Unable to {$operation} {$target}".($error ? " ({$error})" : '').'.');
    }
}
