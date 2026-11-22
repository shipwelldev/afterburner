<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Publication;

use Laravel\Roster\Package;
use Shipwell\Afterburner\Bundles\BundledContent;
use Shipwell\Afterburner\Configuration\Preset;
use Shipwell\Afterburner\Configuration\Settings;
use Shipwell\Afterburner\Discovery\PackageInventory;
use Shipwell\Afterburner\Discovery\PackageRegistry;
use Shipwell\Afterburner\Rendering\RenderingContext;

/**
 * Collects the candidate guideline and skill sources for one publication,
 * applying Boost-compatible identities, applicability, exclusions, and
 * application overrides.
 */
class SourceCatalog
{
    protected ApplicationSources $application;

    public function __construct(
        protected readonly string $basePath,
        protected readonly Settings $settings,
        protected readonly Preset $base,
        protected readonly PackageInventory $inventory,
    ) {
        $this->application = new ApplicationSources($basePath);
    }

    public function inventory(): PackageInventory
    {
        return $this->inventory;
    }

    /**
     * Guideline sources keyed by identity, in composition order.
     *
     * Application guidelines that do not replace another source come first,
     * followed by bundled, package, and third-party guidelines. An application
     * guideline whose relative path matches a source's override key replaces
     * that source in place (a Blade variant wins over Markdown).
     *
     * @return array<string, GuidelineSource>
     */
    public function guidelines(): array
    {
        $applicationFiles = $this->application->guidelineFiles();
        $used = [];
        $base = [];

        foreach ($this->baseGuidelines() as [$source, $overrideKey]) {
            if (isset($applicationFiles[$overrideKey])) {
                $source = $source->withPath($applicationFiles[$overrideKey], Origin::Application);
                $used[$overrideKey] = true;
            }

            if ($source->path !== '') {
                $base[$source->key] = $source;
            }
        }

        $guidelines = [];

        foreach ($applicationFiles as $key => $path) {
            if (! isset($used[$key])) {
                $guidelines['.ai/'.$key] = new GuidelineSource('.ai/'.$key, $path, Origin::Application);
            }
        }

        return [...$guidelines, ...$base];
    }

    /**
     * Skill sources in precedence order: a later source with the same name wins.
     *
     * The order follows Boost: bundled core and integration skills, bundled and
     * first-party package skills, third-party skills, package-specific
     * application skills, and finally explicit application skills.
     *
     * @return array<int, SkillSource>
     */
    public function skills(): array
    {
        return [
            ...$this->bundledCoreSkills(),
            ...$this->integrationSkills(),
            ...$this->packageSkills(),
            ...$this->thirdPartySkills(),
            ...$this->packageSpecificApplicationSkills(),
            ...$this->application->explicitSkills(),
        ];
    }

    /**
     * Skill folders that were ignored because they have no entry point.
     *
     * @return array<int, string>
     */
    public function ignoredSkillDirectories(): array
    {
        return $this->application->skillDirectoriesWithoutEntry();
    }

    /**
     * Base guideline candidates with the application override key for each.
     *
     * A candidate with an empty path is an identity slot that only an
     * application override can fill.
     *
     * @return array<int, array{0: GuidelineSource, 1: string}>
     */
    protected function baseGuidelines(): array
    {
        return [
            ...$this->bundledEcosystemGuidelines(),
            ...$this->packageGuidelines(),
            ...$this->thirdPartyGuidelines(),
        ];
    }

    /**
     * @return array<int, array{0: GuidelineSource, 1: string}>
     */
    protected function bundledEcosystemGuidelines(): array
    {
        $ecosystem = BundledContent::ecosystem($this->base);

        if ($ecosystem === null) {
            return [];
        }
        $guidelines = [];

        $add = function (string $key, string $relative, ?string $catalog) use (&$guidelines): void {
            $path = $catalog === null ? null : BundledContent::guideline($catalog, $relative);

            if ($path !== null) {
                $guidelines[] = [new GuidelineSource($key, $path, Origin::Bundled), $relative];
            }
        };

        $add('foundation', 'foundation', $ecosystem);
        $folder = (string) BundledContent::coreFolder($this->base);
        $add($folder, $folder.'/core', $ecosystem);
        $add('php', 'php/core', $ecosystem);
        $add('deployments', 'deployments/core', $ecosystem);

        if (RenderingContext::usesHerd($this->basePath, $this->settings)) {
            $add('herd', 'herd/core', $ecosystem);
        }

        if (RenderingContext::usesSail($this->basePath, $this->settings)) {
            $add('sail', 'sail/core', $ecosystem);
        }

        if (RenderingContext::enforcesTests($this->basePath, $this->settings)) {
            $add('tests', 'enforce-tests', $ecosystem);
        }

        return $guidelines;
    }

    /**
     * Package guidance: a first-party package's own `core` guideline (or the
     * bundled equivalent) and bundled guidelines for its installed major version.
     *
     * @return array<int, array{0: GuidelineSource, 1: string}>
     */
    protected function packageGuidelines(): array
    {
        $guidelines = [];

        foreach ($this->inventory->applicable() as $package) {
            if (! $this->settings->guidelinesEnabledFor($package->name())) {
                continue;
            }

            $name = PackageRegistry::guidelineName($package->name());
            $core = $this->firstPartyCoreGuideline($package) ?? $this->bundledPackageCoreGuideline($name) ?? '';

            $guidelines[] = [new GuidelineSource($name.'/core', $core, $core === '' ? Origin::Bundled : $this->originOf($core), $package->name()), $name.'/core'];

            foreach ($this->bundledPackageVersionGuidelines($name, $package) as $entry) {
                $guidelines[] = $entry;
            }
        }

        return $guidelines;
    }

    protected function firstPartyCoreGuideline(Package $package): ?string
    {
        if (! PackageRegistry::isFirstParty($package)) {
            return null;
        }

        $directory = PackageRegistry::boostPath($package, 'guidelines');

        if ($directory === null) {
            return null;
        }

        foreach (['core.blade.php', 'core.md'] as $file) {
            if (is_file($directory.DIRECTORY_SEPARATOR.$file)) {
                return $directory.DIRECTORY_SEPARATOR.$file;
            }
        }

        return null;
    }

    protected function bundledPackageCoreGuideline(string $name): ?string
    {
        $ecosystem = BundledContent::ecosystem($this->base);

        return $ecosystem === null ? null : BundledContent::guideline($ecosystem, $name.'/core');
    }

    /**
     * Bundled guidelines for the installed major version, keyed `<name>/v<major>[/<file>]`.
     *
     * @return array<int, array{0: GuidelineSource, 1: string}>
     */
    protected function bundledPackageVersionGuidelines(string $name, Package $package): array
    {
        $ecosystem = BundledContent::ecosystem($this->base);

        if ($ecosystem === null || $package->major() === null) {
            return [];
        }

        $guidelines = [];

        foreach (ApplicationSources::guidelineFilesIn(Paths::join($ecosystem, $name, (string) $package->major())) as $relative => $path) {
            $file = basename($relative);
            $key = $name.'/v'.$package->major().($file === 'core' ? '' : '/'.$file);

            $guidelines[] = [new GuidelineSource($key, $path, Origin::Bundled, $package->name()), $name.'/'.$package->major().'/'.$relative];
        }

        return $guidelines;
    }

    /**
     * Guidelines shipped by direct third-party dependencies, keyed by full package name and relative path.
     *
     * @return array<int, array{0: GuidelineSource, 1: string}>
     */
    protected function thirdPartyGuidelines(): array
    {
        $guidelines = [];

        foreach ($this->inventory->thirdParty() as $package) {
            if (! $this->settings->guidelinesEnabledFor($package->name())) {
                continue;
            }

            $directory = PackageRegistry::boostPath($package, 'guidelines');

            if ($directory === null) {
                continue;
            }

            foreach (ApplicationSources::guidelineFilesIn($directory) as $relative => $path) {
                $key = $package->name().'/'.$relative;
                $guidelines[] = [new GuidelineSource($key, $path, Origin::Dependency, $package->name()), $key];
            }
        }

        return $guidelines;
    }

    /**
     * @return array<int, SkillSource>
     */
    protected function bundledCoreSkills(): array
    {
        if ($this->base === Preset::None) {
            return [];
        }

        $ecosystem = BundledContent::ecosystem($this->base);

        return [
            ...($ecosystem === null ? [] : ApplicationSources::skillsIn(Paths::join($ecosystem, (string) BundledContent::coreFolder($this->base), 'skill'), Origin::Bundled)),
            ...BundledContent::coreSkills(),
        ];
    }

    /**
     * Skills for opted-in integrations (the Laravel Cloud deployment skill).
     *
     * @return array<int, SkillSource>
     */
    protected function integrationSkills(): array
    {
        $ecosystem = BundledContent::ecosystem($this->base);

        if ($ecosystem === null || ! $this->settings->cloud) {
            return [];
        }

        return ApplicationSources::skillsIn(Paths::join($ecosystem, 'deployments', 'skill'), Origin::Bundled);
    }

    /**
     * Bundled package skills for the installed major version, then the
     * package's own first-party skills, for each applicable package.
     *
     * @return array<int, SkillSource>
     */
    protected function packageSkills(): array
    {
        $skills = [];

        foreach ($this->inventory->applicable() as $package) {
            if (! $this->settings->skillsEnabledFor($package->name())) {
                continue;
            }

            $name = PackageRegistry::guidelineName($package->name());

            array_push($skills, ...$this->bundledPackageSkills($name, $package));

            if (PackageRegistry::isFirstParty($package) && ($directory = PackageRegistry::boostPath($package, 'skills')) !== null) {
                array_push($skills, ...ApplicationSources::skillsIn($directory, Origin::Dependency, $package->name()));
            }
        }

        return $skills;
    }

    /**
     * @return array<int, SkillSource>
     */
    protected function bundledPackageSkills(string $name, Package $package): array
    {
        $ecosystem = BundledContent::ecosystem($this->base);

        return $ecosystem === null ? [] : self::versionedSkills(Paths::join($ecosystem, $name), $package, Origin::Bundled);
    }

    /**
     * @return array<int, SkillSource>
     */
    protected function thirdPartySkills(): array
    {
        $skills = [];

        foreach ($this->inventory->thirdParty() as $package) {
            if (! $this->settings->skillsEnabledFor($package->name())) {
                continue;
            }

            if (($directory = PackageRegistry::boostPath($package, 'skills')) !== null) {
                array_push($skills, ...ApplicationSources::skillsIn($directory, Origin::Dependency, $package->name()));
            }
        }

        return $skills;
    }

    /**
     * Application skills for an installed package: `.ai/<name>/skill`, then
     * `.ai/<name>/<installed major>/skill`. These remain eligible when the
     * package's own content is switched off.
     *
     * @return array<int, SkillSource>
     */
    protected function packageSpecificApplicationSkills(): array
    {
        $skills = [];

        foreach ($this->inventory->applicable() as $package) {
            $root = Paths::join($this->basePath, '.ai', PackageRegistry::guidelineName($package->name()));

            if (! is_dir($root)) {
                continue;
            }

            array_push($skills, ...self::versionedSkills($root, $package, Origin::Application));
        }

        return $skills;
    }

    /**
     * @return array<int, SkillSource>
     */
    protected static function versionedSkills(string $root, Package $package, Origin $origin): array
    {
        $skills = ApplicationSources::skillsIn(Paths::join($root, 'skill'), $origin, $package->name());

        if ($package->major() !== null) {
            array_push($skills, ...ApplicationSources::skillsIn(Paths::join($root, (string) $package->major(), 'skill'), $origin, $package->name()));
        }

        return $skills;
    }

    protected function originOf(string $path): Origin
    {
        return str_starts_with($path, Paths::join($this->basePath, '.ai').DIRECTORY_SEPARATOR) ? Origin::Application : Origin::Dependency;
    }
}
