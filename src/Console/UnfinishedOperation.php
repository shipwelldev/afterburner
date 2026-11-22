<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Console;

/**
 * Reports an operation whose behavior is supplied by a later development stage.
 */
trait UnfinishedOperation
{
    protected function unfinished(string $operation): int
    {
        $this->components->error("{$operation} is not available in this development build of Afterburner. Nothing was changed.");

        return self::FAILURE;
    }
}
