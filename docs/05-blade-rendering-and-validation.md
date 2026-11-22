---
description: "Render compatible Blade assets and enforce a complete prepublication validation gate."
status: active
---

# Blade rendering and validation

## Purpose

Unchanged Boost packages work because their dynamic templates render: templates compute instructions, skill metadata, commands, and scoped content from the application context.

## Dependencies

[04 — Discovery and precedence](04-dependency-discovery-and-precedence.md) and [03 — Publication](03-application-asset-publication.md). The released baseline recorded in stage 01 defines the authoring surface; [the compatibility notes](boost-compatibility-notes.md) inventory it. Stage 09 renders the full matching snapshot through this pipeline.

## Configuration

The rendering context comes from the actual application and the shared configuration: the Roster package inventory (versions and package managers), `integrations.sail`, `integrations.cloud`, `enforce_tests`, the selected skills, and the dependency packages whose guidelines are selected. No setting can advertise an MCP server. Exclusions and preset selection come from the shared configuration; there is no renderer-specific source list.

## Rendering contract

Guideline `.blade.php` sources and skill `SKILL.blade.php` entry points render with Blade. A Blade entry point wins over `SKILL.md` in the same folder; the result is published as agent-readable `SKILL.md`, and the losing Markdown entry point is not copied. Supporting `.blade.php` files render to `.md` at the same relative path; other supporting files are copied unchanged. Rendered Blade output is normalized with Boost's Markdown formatter. Plain Markdown is never evaluated as Blade. If a rendered supporting file would publish to the same path as another file in the skill (for example `guide.blade.php` and `guide.md`), publication fails before any output changes. Compiled views are deleted after each render.

Templates receive `$assist`, implemented without Boost by `Shipwell\Afterburner\Rendering\GuidelineAssist` with Boost v2.10.4's method names and behavior:

- `project` (the Roster project, so `$assist->project->php()->package(...)` and `->js()->uses(...)` work), `config` (a `GuidelineConfig` with Boost's property names plus `purpose` from `afterburner.json`), and `skills` (selected skill names and descriptions).
- `hasPackage($package, $constraint)` for PHP or JavaScript packages, `supportsPintAgentFormatter()`, `hasComposerScript()`, `enums()` and `enumContents()`, `appPath()`.
- Command builders `artisan()`, `artisanCommand()`, `composerCommand()`, `binCommand()`, `nodePackageManager()`, `nodePackageManagerCommand()`, and `sailBinaryPath()`. They use Sail prefixes (`vendor/bin/sail artisan`, `vendor/bin/sail composer`, `vendor/bin/sail bin`) when Sail is in use, the JavaScript package manager detected from the committed lockfile, and any `boost.executable_paths` overrides an application still has in `config/boost.php`.
- `inertia()` with `gte()`, `hasFormComponent()`, `hasFormComponentResets()`, and `pagesDirectory()`.
- Capability flags: `hasMcpEnabled()` and `config->hasMcp` are always false, `hasSkillsEnabled()` is true, and `hasCloudEnabled()` reflects `integrations.cloud`.
- Boost's `Laravel\Boost\Support\PackageRegistry` package-name constants (for example `PackageRegistry::INERTIA_VUE`) and its `guidelineName()`, `rosterName()`, `isFirstParty()`, and `boostPath()` helpers, which Boost-authored templates reference directly. While rendering, a fallback autoloader aliases that class name to `Shipwell\Afterburner\Compatibility\BoostPackageRegistry`; because it runs after Composer's autoloader, an installed Boost still provides its own class, and nothing is registered at application boot.

Sail is in use when `integrations.sail` is true, or, when it is unset, when Sail is installed (its binary plus a Compose file) and active (`LARAVEL_SAIL=1` outside a dev container). The test-enforcement flag is `enforce_tests`, or, when unset, whether the application has a PHPUnit/Pest runner and at least six detectable test cases.

Before Blade runs, the renderer applies Boost's pre-processing: backticks, `<?php`, `@volt`/`@endvolt`, `@can`, `@include`, `@props`, and `<x-`/`</x-` component tags are masked; `@{{`, `@{!!`, and `@@` escapes are preserved; ampersands inside fenced code are protected from entity decoding; and `@boostsnippet('name', 'lang') … @endboostsnippet` becomes a labelled fenced block in which only `{{ $assist->… }}` expressions render. Package prose and code examples are preserved; Boost tool names are not rewritten (the stage 08 compatibility skill handles those requests).

## Scoped directives

`@scoped([...globs])` / `@endscoped` outside fenced code is recognized; inside fences and when escaped as `@@scoped` it stays literal. Scoped bodies render inline because extraction is off by default. Each top-level block with globs and a nonempty body is recorded with its globs and body for stage 06, along with a version of the guideline from which recorded blocks can be removed. Nested, empty, and path-less blocks keep their body text inline and are not recorded, matching the baseline. A real template parse or render failure still fails publication.

## Selection and validation order

1. Resolve effective preferences and source exclusions.
2. Discover candidate sources and the information needed to identify them.
3. Render every candidate skill's entry point to establish its frontmatter identity, then apply precedence. Guideline sources replaced by an application override are swapped before rendering, so the replaced file is never read.
4. Render and validate the selected guidelines and the winning skills' supporting files.
5. Prepare guideline documents, skill folders, scoped metadata, and the proposed ownership record.
6. Only after the complete plan is valid, write output and persist state.

An eligible skill whose entry point cannot render or lacks valid frontmatter fails the command even if an application skill might have replaced it, because its identity cannot be known. Required skill metadata is nonempty `name` and `description` in valid frontmatter after rendering, and published names and supporting paths must stay inside the destination. A selected unreadable file, malformed frontmatter, missing field, or render error fails the command; excluded content is never read.

## Failure behavior

Failures name the source path and the underlying error (for example ``.ai/guidelines/broken.blade.php: the template failed to render: Call to undefined method …``). Existing generated documents, skills, rules, `afterburner.json`, and compiled views are unchanged after a selected-input failure. This intentionally differs from Boost, which skips templates that fail to render.

Templates are the application's and packages' trusted authoring code; there is no sandbox, and no transaction is claimed over template side effects, filesystem errors, or Composer operations.

## Testing

`tests/Feature/BladeRenderingTest.php` runs install/update with fixtures covering every command builder and helper from the real environment (pnpm detection, Composer scripts, app paths), Sail selected/installed/active/declined, `boost.executable_paths` overrides, `PackageRegistry` constants without Boost installed, capability flags (no MCP, skills, Cloud), PHP and JavaScript package and major checks through `hasPackage` and `$assist->project`, Inertia helpers across versions, enum discovery, literal Markdown and protected code examples/escapes/`@verbatim`, `@boostsnippet`, inline scoped content (including empty, nested, escaped, and fenced forms), Blade skills with rendered metadata beating a Markdown entry point and rendering supporting references to `.md`, and a Blade application override beating its Markdown variant. Failure cases seed output first and compare the whole tree and compiled views afterward for helper errors, syntax errors, skill entry errors, metadata that renders empty, supporting-template errors, and rendered-name collisions. It also covers an invalid guideline replaced by an application override, invalid excluded dependency templates, and a vendor skill whose identity cannot render.

## Acceptance criteria

- [x] Compatible dynamic package/application assets render without Boost installed.
- [x] Plain Markdown remains literal and rendered skills become valid agent entry points.
- [x] Template context/flags reflect the actual application and no running MCP server.
- [x] Scoped content remains present inline by default, with metadata available for extraction.
- [x] Required identity/metadata and supporting assets are validated before writes.
- [x] Selected validation failures preserve previous generated output and published state.
- [x] Excluded content and resolvable unused guideline overrides do not cause spurious failures.

## Dev plan

1. Inventory the pinned authoring helpers/directives and prepare representative fixtures.
2. Implement rendering and dynamic skill identity resolution.
3. Add the shared prepublication validation gate and scoped metadata.
4. Exercise success/failure through the canonical install/update commands.

## Out of scope

There is no template sandbox, second authoring language, arbitrary Boost PHP extension compatibility, or MCP tool execution. Scoped extraction itself belongs to stage 06.
