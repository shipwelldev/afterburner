<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Discovery;

use Laravel\Roster\Package;
use Laravel\Roster\ProjectManager;

/**
 * The application's installed PHP and JavaScript packages, read from its lockfiles.
 *
 * Packages keep the incoming inventory order: Composer packages (production,
 * then development) followed by JavaScript packages. Installed locations come
 * from Composer's configured vendor directory and node_modules.
 */
final class PackageInventory
{
    /** @var array<int, Package>|null */
    private ?array $packages = null;

    public function __construct(private readonly ProjectManager $project)
    {
        //
    }

    /**
     * Scan the application at its current base path, bypassing any cached scan.
     */
    public static function scan(): self
    {
        $project = new ProjectManager;
        $project->fresh();

        return new self($project);
    }

    public function project(): ProjectManager
    {
        return $this->project;
    }

    /**
     * @return array<int, Package>
     */
    public function packages(): array
    {
        return $this->packages ??= [
            ...$this->project->php()->packages()->all(),
            ...$this->project->js()->packages()->all(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_map(fn (Package $package): string => $package->name(), $this->packages());
    }

    /**
     * Installed packages eligible for package-specific guidance and skills, in inventory order.
     *
     * @return array<int, Package>
     */
    public function applicable(): array
    {
        $names = $this->names();

        return array_values(array_filter(
            $this->packages(),
            fn (Package $package): bool => ! PackageRegistry::isSuppressed($package, $names),
        ));
    }

    /**
     * Direct, non-first-party packages, which contribute their own Boost assets.
     *
     * @return array<int, Package>
     */
    public function thirdParty(): array
    {
        return array_values(array_filter(
            $this->packages(),
            fn (Package $package): bool => $package->isDirect() && ! PackageRegistry::isFirstParty($package),
        ));
    }
}
