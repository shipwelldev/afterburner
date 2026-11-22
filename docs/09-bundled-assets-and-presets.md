---
description: "Ship the attributed, Afterburner-adapted Boost snapshot and a separately curated copy, and compose the four base presets."
status: active
---

# Bundled assets and presets

## Purpose

The configurable bundled content library works without Boost installed and without downloading content during application setup. The Boost snapshot stays traceable to its release, Afterburner's adaptations are recorded separately from it, and both are kept apart from the maintainer's manual curation.

## Dependencies

[04 — Discovery](04-dependency-discovery-and-precedence.md), [05 — Rendering](05-blade-rendering-and-validation.md), and [08 — Core skills](08-core-operational-skills.md). Bundles go through the same selection, override, and publication pipeline as every other source.

## Configuration

`base` and the shared package and integration preferences select bundled content. There is no remote preset URL, install-time synchronization setting, or catalog-selection file. Explicit choices stay authoritative over the fresh and replacement defaults.

## Upstream snapshot and provenance

`resources/boost` holds the complete `.ai` directory of Laravel Boost `v2.10.4` (commit `9cd5e7d81300b5dee4c8b1619ec4b36f4a6367fd`), the release recorded in stage 01: 77 guideline, skill, and supporting files in Boost's own package-grouped layout, with Afterburner's adaptations applied. Alongside them are Boost's MIT `LICENSE.md`, `ADAPTATIONS.patch`, and `PROVENANCE.json`, which records the package, tag, commit, source URL, copy date, a SHA-256 hash for every file as released, and the list of adapted files. Only the released tag was copied; no `main`-branch files were mixed in. Reverse-applying `ADAPTATIONS.patch` to the shipped files reproduces the release exactly.

The adaptations (51 files) make the snapshot read Afterburner's configuration and drop what only applies to Boost's MCP server:

- `config('boost.purpose')` is replaced by the `purpose` field of `afterburner.json` (`$assist->config->purpose`); the `config('boost.rules.enabled')` condition is removed because Afterburner's rules are always available; Boost's `Laravel\Boost\Support\PackageRegistry` constants are replaced by `Shipwell\Afterburner\Discovery\PackageRegistry` constants; and the `@var` type hints for `$assist` name `Shipwell\Afterburner\Rendering\GuidelineAssist` instead of Boost's class. No `Laravel\Boost` class is referenced anywhere in either copy.
- Every `hasMcpEnabled()` branch is removed: the "Laravel Boost is an MCP server" tools section, its `browser-logs` and search-syntax guidance, and the `tinker` MCP tool alternative (the CLI Tinker guidance stays).
- Instructions to call a Boost MCP tool point at the Afterburner skill that replaces it: `search-docs` at `afterburner-documentation`, `get-absolute-url` at `afterburner-application-inspection`, and `infer-conventions`' `record-rule` steps at the `afterburner-project-rules` recording steps and `afterburner:index-rules`. The Laravel 11 note about listing commands with Boost's MCP tool now uses `php artisan list`.

The `mcp-development` skill remains: it teaches applications that use the `laravel/mcp` package to build their own MCP servers and is unrelated to Boost's server; only its `search-docs` references were repointed.

These files ship in the Composer distribution. Installation and updates read them from the installed package and never download assets. Maintainers move to a new release with `bin/refresh-boost-snapshot <boost-checkout> --tag=<release> [--commit=<sha>]`, which assembles the new release in a temporary directory, reapplies `ADAPTATIONS.patch`, and only then replaces `resources/boost` (assets, license, patch, and provenance), printing the added, changed, and removed files. If the patch no longer applies, nothing is replaced and the conflicting files are reported. After editing adapted files by hand, `bin/refresh-boost-snapshot <boost-checkout> --record-adaptations` regenerates the patch against the pristine release. The curated copy is never touched. Changing the baseline also means updating `tests/Fixtures/boost-baseline.json` and the affected compatibility fixtures.

`resources/afterburner` is the separately maintained curated copy. It started from the adapted snapshot, and the maintainer curates it by hand; its content is not fixed by this spec or by tests. It keeps the snapshot's package-grouped layout, except that its own core guideline and skills live in `afterburner/` (`afterburner/core.blade.php`, `afterburner/skill/`) instead of `boost/`. It ships no code style guidelines or skills: an application supplies its own coding standard as application guidelines and skills, the foundation guideline tells agents to follow existing code conventions only where that standard is silent, and `infer-conventions` records conventions only where it is silent. `LICENSE.md` carries Boost's license, and `CURATION.md` and `PROVENANCE.json` are the maintainer's notes on the copy.

## Preset composition

| `base` | Composition |
| --- | --- |
| `boost` | Core operational assets plus applicable ecosystem assets from the adapted release snapshot |
| `afterburner` | Core operational assets plus applicable ecosystem assets from the separate curated copy |
| `core` | Only Afterburner's core skills, which replace Boost's MCP tools; no guidelines and no other skills |
| `none` | No bundled assets |

Bundled guidelines come only from the ecosystem catalog of the `boost` and `afterburner` presets and follow Boost's composition: `foundation`, the catalog's own core guideline (`boost/core` with identity `boost` in the snapshot, `afterburner/core` with identity `afterburner` in the curated copy), `php` (`php/core`), and `deployments` (`deployments/core`); then the conditional `herd` (a `.test` `APP_URL` with Herd installed and Sail not in use), `sail` (when Sail is in use), and `tests` (`enforce-tests`, when test enforcement applies); then, for each applicable package, `<name>/core` (a first-party package's own core guideline wins over the bundled one) and the installed major's `<name>/v<major>[/<file>]` guidelines. Bundled skills are the catalog's own skills in `boost/skill` or `afterburner/skill` (`infer-conventions`, adapted to Afterburner's rule workflow), Afterburner's core skills, the `deploying-to-cloud` integration skill when `integrations.cloud` is true, and each applicable package's `<name>/skill` and `<name>/<major>/skill` folders followed by its first-party vendor skills. The snapshot's PHP-version folders (`php/8.x`) are unused, as in Boost v2.10.4.

Every preset still includes eligible application and dependency sources, application overrides apply to bundled identities (for example `.ai/guidelines/foundation.md` or `.ai/guidelines/laravel/12/core.md`), and package switches filter bundled package guidance and skills (for example `packages["laravel/framework"].skills = false` removes `laravel-best-practices`). Convention inference comes from the snapshot's `infer-conventions` skill, which records approved conventions through the `afterburner-project-rules` steps.

Package and major applicability, transitive and suppression rules, and integration preferences follow the snapshot. The bundled copies contain no Boost configuration checks and no MCP-specific content. Boost tool references in third-party packages' prose are left as written and handled by the compatibility skill.

`core` is limited to the five core skills: no guidelines, no `infer-conventions`, and no ecosystem content, even when ecosystem packages are installed. `none` is literal: no compatibility, inspection, or other core skill, and no ecosystem content. It does not unregister commands or suppress user rules or content. Scoped extraction applies to selected application and package guidance and never adds bundled instructions to `none`.

## Defaults and updates

Fresh installation without an explicit preset uses `afterburner`; explicit replacement without one uses `boost`; saved and interactive choices win. Changing presets refreshes the managed outputs and removes obsolete bundled guidelines and skills with the same ownership rules as application content, leaving custom skills and manual text alone.

Install and update never fetch or update the snapshot. Only a new Afterburner release changes the distributed snapshot, and refreshing it never overwrites the curated copy.

## Testing

`tests/Feature/BundledPresetsTest.php` exercises every preset through install: the exact guideline identities and skills for an empty application , the rendered foundation (with `purpose` from `afterburner.json`, ignoring `config('boost.purpose')`) and `boost` guidelines without MCP content, application and dependency content in every preset, Laravel 11/12/13 versioned guidance with no fallback, versioned Livewire/Tailwind/Inertia skills with supporting files, transitive and suppressed packages, the Cloud integration, Sail and test-enforcement guidance (configured and detected), application overrides of bundled guidelines and skills, package switches over bundled content, and preset transitions from `boost` through `afterburner`, `core`, and `none` that remove stale bundled output while keeping a custom skill and manual text.

It also renders the entire snapshot for four representative application stacks (Livewire 2/3/4, Inertia 1/2/3 across React/Vue/Svelte, Flux free/pro, Volt, Folio, Pennant, MCP, Wayfinder, Pest/PHPUnit, Pint, Sail, Tailwind 3/4, Laravel 11/12/13, Cloud, and test enforcement) and checks that no template syntax or placeholders leak into the output and that no ecosystem guideline or skill mentions `search-docs` or `record-rule`. A source check confirms the Boost copy contains no `config('boost.…')`, `hasMcpEnabled`, `Laravel\Boost` class, or Boost MCP tool references. Distribution checks reverse-apply `ADAPTATIONS.patch` and verify the result against the recorded baseline and every released file hash, verify that exactly the listed files are adapted, and check the license. The curated copy is edited by hand, so no test asserts its content; tests only require that it ships with its license and that refreshing the snapshot leaves it untouched. A refresh simulation runs `bin/refresh-boost-snapshot` against a modified pristine upstream checkout and verifies the new files arrive with the adaptations reapplied while a deliberate curated edit survives byte-for-byte, and that an upstream change to adapted lines stops the refresh without touching the snapshot. Network access is blocked during publication by replacing the `http`, `https`, and `ftp` stream wrappers and preventing stray Laravel HTTP requests, and `tests/Process/OfflineInstallTest.php` installs both ecosystem presets in a real Artisan process with every HTTP proxy variable pointing at a closed port.

## Acceptance criteria

- [x] The vendored released snapshot has an exact recorded source, retained attribution/license, and recorded, reversible adaptations.
- [x] Snapshot and curated copy are separate, distributed assets.
- [x] Initial curation removes only the agreed Cloud promotion.
- [x] All four preset compositions/default contexts match the overview.
- [x] Application/package content and overrides remain usable in every preset.
- [x] Preset transitions remove stale managed content without touching custom assets.
- [x] Installation/update uses shipped content and never refreshes the snapshot remotely.
- [x] Upstream snapshot changes reapply the adaptations and preserve deliberate curated-copy edits.
- [x] Bundled guidance reads Afterburner's configuration and contains nothing specific to Boost's MCP server.

## Dev plan

1. Pin and attribute a release snapshot and include complete supporting assets.
2. Adapt the snapshot to Afterburner's configuration without MCP content, and record the adaptations as a patch.
3. Initialize the separate curated copy from the adapted snapshot and remove the promotion only.
4. Connect preset selection to the shared source/composition pipeline.
5. Add preset transitions, distribution, provenance, and no-fetch tests.

## Out of scope

There is no live synchronization service, remote preset registry, automatic broad content pruning, or dependency on an installed Boost package.
