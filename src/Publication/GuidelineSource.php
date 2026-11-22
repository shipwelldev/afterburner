<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

/**
 * A candidate guideline file and the identity it is composed under.
 */
final readonly class GuidelineSource
{
    public function __construct(
        public string $key,
        public string $path,
        public Origin $origin,
        public ?string $package = null,
    ) {
        //
    }

    public function withPath(string $path, Origin $origin): self
    {
        return new self($this->key, $path, $origin, $this->package);
    }

    public function isBlade(): bool
    {
        return str_ends_with($this->path, '.blade.php');
    }
}
