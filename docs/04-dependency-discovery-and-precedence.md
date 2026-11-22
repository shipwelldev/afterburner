---
description: "Compose application and dependency assets with Boost-compatible applicability, identities, exclusions, and overrides."
status: active
---

# Dependency discovery and precedence

## Purpose

Packages designed for Boost work without changing their asset layout. Dependency sources feed the stage 03 publication pipeline while preserving Boost's identities and ordering.

## Dependencies

[03 — Application publication](03-application-asset-publication.md) supplies the observable output boundary. [02 — Configuration](02-configuration-and-agent-selection.md) supplies dependency switches. Bundled catalogs (stage 09) plug into the same selection rules without changing precedence.

## Configuration

The independent `packages[full-name].guidelines` and `.skills` switches from stage 02 apply; omitted switches mean enabled. There is no allowlist that reverses publish-by-default.

## Source contracts

| Source | Public convention |
| --- | --- |
| Application guidelines | Markdown/Blade below `.ai/guidelines` |
| Explicit application skills | `.ai/skills/<directory>/SKILL.md` or `SKILL.blade.php` |
| Package-specific application skills | `.ai/<normalized-package>/skill/<directory>` and its installed-major variant beneath `.ai/<normalized-package>/<major>/skill` |
| Dependency guidelines | `resources/boost/guidelines` in an installed package |
| Dependency skills | `resources/boost/skills/<directory>/SKILL.md` or `SKILL.blade.php` |
| Bundled sources | The compatible core/package/major/integration catalog supplied by stage 09 |

The installed package inventory comes from `laravel/roster` 1.0, the inventory the pinned Boost release uses: Composer `packages` then `packages-dev` from `composer.lock`, followed by the JavaScript lockfile's packages. Directness comes from `composer.json`/`package.json`, the installed major from the locked version, and installed locations from Composer's configured `vendor-dir` and `node_modules` rather than an assumed vendor tree. A package without asset directories contributes nothing.

Normalized package names follow the baseline table (`laravel/framework` → `laravel`, `livewire/flux` → `fluxui-free`, `@inertiajs/vue3` → `inertia-vue`, …); other names are lowercased with `@` removed and `/` and `_` replaced by `-` (`acme/my_pkg` → `acme-my-pkg`, `@acme/ui` → `acme-ui`).

Applicability follows the baseline (see [the compatibility notes](boost-compatibility-notes.md)):

- First-party packages (the `laravel/*` Composer scope, Livewire, Flux, Volt, Inertia, Pest, PHPUnit, and the `@inertiajs/*`, `@laravel/*`, `laravel-echo`, `laravel-precognition`, `laravel-vite-plugin` NPM names) contribute their `resources/boost/guidelines/core` as the `<normalized>/core` guideline and their `resources/boost/skills`. Other files in a first-party guideline directory are not third-party guidelines.
- Direct, non-first-party Composer and NPM dependencies with `resources/boost/guidelines` or `resources/boost/skills` are third-party sources and are included by default. Transitive dependencies are never third-party sources.
- `laravel/boost` and `laravel/sail` never contribute package guidance; `laravel/mcp` and `livewire/livewire` contribute only as direct requirements; `pestphp/pest` suppresses `phpunit/phpunit` and `livewire/flux-pro` suppresses `livewire/flux`.
- Versioned content is selected only for the installed major version. A missing major directory selects nothing, and no other version directory is used as a fallback. Numeric-looking subdirectories inside a third-party guideline directory are ordinary path segments.

## Exclusions and application ownership

`packages[full-name].guidelines` and `.skills` independently filter that dependency's content, including bundled package-specific guidance and skills. Foundation/core operational content is controlled by the base preset, not by package switches.

Exclusions apply before any excluded file is read or rendered, so a broken or unreadable excluded asset does not fail publication. Application sources remain eligible under exclusions: an application guideline that would have replaced an excluded source is published as an ordinary application guideline (`.ai/<path>`), and package-specific application skills for an installed package are published even when that package's own skills are switched off.

## Guideline identities

A third-party guideline identity is the full package name followed by the relative guideline path without `.md` or `.blade.php`: `acme/a/core` and `acme/b/core` coexist, as do `acme/a/admin/core` and `acme/a/api/core`, and `@acme/ui/core` for an NPM package. First-party package guidance uses `<normalized>/core` and bundled versioned guidance `<normalized>/v<major>[/<file>]`.

An application guideline beneath `.ai/guidelines` whose relative path (without extension) equals a source's override key replaces that source in place: `.ai/guidelines/acme/a/core.md` replaces `acme/a/core`, and `.ai/guidelines/laravel/core.md` fills the `laravel/core` slot of an installed `laravel/framework`. The Blade variant wins over Markdown. A replacing application file is not also published as unrelated custom guidance. Remaining application guidelines are composed first, then bundled, package, and third-party guidelines, each under its full identity heading.

## Skill identities and merge order

Skills are keyed globally by rendered frontmatter `name`. Sources are applied in Boost's order, with later entries replacing earlier ones of the same name: bundled core and integration skills, bundled package skills (root, then installed major) followed by first-party vendor skills for each applicable package, third-party vendor skills in inventory order (PHP packages, then JavaScript packages), package-specific application skills (root, then installed major), and explicit application skills. Valid same-name dependency skills are not an error and are never sorted alphabetically; application content is the final authority. Stage 05 renders dynamic skill metadata before identities are resolved.

## Testing

`tests/Feature/DependencyDiscoveryTest.php` uses local fixture packages (manifest, lockfile, and installed files) with the public install/update commands. It covers same-basename and nested guidelines across PHP and NPM packages, incoming-order skill winners in both orders, JavaScript after PHP, application skill precedence, full-identity application guideline overrides, package-specific skills for the installed major only (including normalized PHP and NPM names and an uninstalled package), no major fallback, first-party naming and core-only guidelines, the baseline suppression rules (Pest over PHPUnit, transitive MCP, Boost), transitive third-party packages, a custom `vendor-dir`, every guideline/skill switch combination, application content surviving exclusion, malformed and unreadable excluded assets (which fail once re-enabled), dependency removal through update with stage 03 cleanup, and newly added dependencies on update. Assertions target the published identities and content.

## Acceptance criteria

- [x] Direct Composer and NPM Boost assets publish without package-author changes.
- [x] Dependency content is included by default and switches filter each type independently.
- [x] Application-authored content remains available under package exclusions.
- [x] Guideline namespaces prevent unrelated same-basename collisions.
- [x] Skill winners match Boost's incoming-order semantics, with application precedence last.
- [x] Applicable package/major variants and normalized identities match the pinned baseline.
- [x] Dependency changes feed the existing managed-output cleanup path.

## Dev plan

1. Add installed dependency inventory and source identity resolution.
2. Integrate source-specific applicability and exclusions before composition.
3. Apply guideline and skill precedence independently.
4. Add command-boundary fixtures for collisions, overrides, versions, and removal.

## Out of scope

Package assets, package manifest formats, and remote guidance are unchanged. Rendering and snapshot acquisition are separate stages.
