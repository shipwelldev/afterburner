<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Configuration;

use RuntimeException;

class InvalidConfiguration extends RuntimeException
{
    public static function malformed(string $file, string $reason): self
    {
        return new self("{$file} could not be read: {$reason}. The file was left unchanged.");
    }

    public static function field(string $file, string $field, string $problem): self
    {
        return new self("{$file} has an invalid `{$field}`: {$problem}. The file was left unchanged.");
    }

    public static function input(string $option, string $problem): self
    {
        return new self("Invalid {$option}: {$problem}.");
    }
}
