<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Bundles;

use Shipwell\Afterburner\Configuration\Preset;
use Shipwell\Afterburner\Publication\ApplicationSources;
use Shipwell\Afterburner\Publication\Origin;
use Shipwell\Afterburner\Publication\Paths;
use Shipwell\Afterburner\Publication\SkillSource;

/**
 * Content shipped inside the Afterburner package. Installation reads these
 * installed files and never downloads content.
 *
 * - `resources/core`: Afterburner's core skills, which replace Boost's MCP tools.
 * - `resources/boost`: the attributed Laravel Boost release snapshot.
 * - `resources/afterburner`: the separately curated copy of that snapshot.
 */
final class BundledContent
{
    public static function root(string $relative = ''): string
    {
        return Paths::join(dirname(__DIR__, 2), 'resources', $relative);
    }

    /**
     * The ecosystem guidance catalog a preset uses, if any.
     */
    public static function ecosystem(Preset $preset): ?string
    {
        return match ($preset) {
            Preset::Boost => self::root('boost'),
            Preset::Afterburner => self::root('afterburner'),
            Preset::Core, Preset::None => null,
        };
    }

    /**
     * The folder in a preset's ecosystem catalog that holds its own core guideline
     * (`<folder>/core`) and skills (`<folder>/skill`): `boost` in the Boost snapshot,
     * `afterburner` in the curated copy. The folder name is also the guideline identity.
     */
    public static function coreFolder(Preset $preset): ?string
    {
        return match ($preset) {
            Preset::Boost => 'boost',
            Preset::Afterburner => 'afterburner',
            Preset::Core, Preset::None => null,
        };
    }

    /**
     * Afterburner's core skills. They are the only content of the `core` preset.
     *
     * @return array<int, SkillSource>
     */
    public static function coreSkills(): array
    {
        return ApplicationSources::skillsIn(self::root('core/skills'), Origin::Bundled, 'shipwelldev/afterburner');
    }

    /**
     * A guideline template in a catalog, preferring Blade over Markdown.
     */
    public static function guideline(string $catalog, string $relative): ?string
    {
        foreach (['.blade.php', '.md'] as $extension) {
            $path = Paths::join($catalog, $relative.$extension);

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
