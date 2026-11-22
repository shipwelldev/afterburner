<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A stream wrapper that records and refuses every network stream open.
 */
final class BlockingStreamWrapper
{
    /** @var array<int, string> */
    public static array $attempts = [];

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$attempts[] = $path;

        return false;
    }

    /**
     * @return array<int, mixed>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        self::$attempts[] = $path;

        return false;
    }
}
