<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Rendering;

/**
 * The guideline configuration exposed to templates as `$assist->config`.
 *
 * Property names match Laravel Boost's GuidelineConfig so existing package
 * templates keep working. `hasMcp` is always false: Afterburner never
 * registers an MCP server.
 */
final class GuidelineConfig
{
    public bool $enforceTests = false;

    public bool $laravelStyle = false;

    public bool $usesSail = false;

    public bool $usesCloud = false;

    public bool $caresAboutLocalization = false;

    public bool $hasAnApi = false;

    public bool $hasSkills = true;

    public bool $hasMcp = false;

    /**
     * The application's purpose from afterburner.json, for the foundation guideline.
     */
    public ?string $purpose = null;

    /**
     * Dependency packages whose guidance is selected.
     *
     * @var array<int, string>
     */
    public array $aiGuidelines = [];
}
