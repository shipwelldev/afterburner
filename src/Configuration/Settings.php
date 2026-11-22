<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Configuration;

/**
 * The validated user preferences and generated state from afterburner.json.
 */
final readonly class Settings
{
    /**
     * @param  array<int, Agent>|null  $agents  null when no selection has been saved
     * @param  array<string, PackageSwitches>  $packages
     */
    public function __construct(
        public ?Preset $base = null,
        public ?array $agents = null,
        public array $packages = [],
        public bool $scopedGuidelines = false,
        public ?bool $sail = null,
        public bool $cloud = false,
        public ?bool $enforceTests = null,
        public ?string $purpose = null,
        public PublishedState $published = new PublishedState,
    ) {
        //
    }

    public function switchesFor(string $package): PackageSwitches
    {
        return $this->packages[$package] ?? new PackageSwitches;
    }

    public function guidelinesEnabledFor(string $package): bool
    {
        return $this->switchesFor($package)->guidelines;
    }

    public function skillsEnabledFor(string $package): bool
    {
        return $this->switchesFor($package)->skills;
    }
}
