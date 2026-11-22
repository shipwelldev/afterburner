# Boost compatibility notes

This document records the released Laravel Boost baseline that Afterburner's compatibility behavior is derived from, and the evidence gathered from it. It does not override the product contract in the [overview](00-project-overview.md) or the numbered specs; where they deliberately differ from Boost, the specs win and the difference is noted here.

## Released baseline

| Item | Value |
| --- | --- |
| Package | `laravel/boost` |
| Release | `v2.10.4` |
| Commit | `9cd5e7d81300b5dee4c8b1619ec4b36f4a6367fd` (2026-10-30) |
| License | MIT |
| Dependency inventory | `laravel/roster` `v1.0.0`, commit `89e518bd88ae98ff50f6082f6b517c8d8e8245fa` |
| Recorded | 2026-11-21 |

The machine-readable record lives in `tests/Fixtures/boost-baseline.json`. Newer `main`-branch sources were consulted during planning only as supporting evidence; fixtures and the shipped snapshot follow this release.

Afterburner depends on `laravel/roster` for the installed PHP/JavaScript package inventory, because that inventory defines Boost's package order, direct/transitive distinction, installed major versions, and installed paths. Roster is a standalone first-party package with no MCP or Boost dependency.

## Discovery and identity

- Package inventory order is Composer `packages`, then `packages-dev` from `composer.lock`, followed by the JavaScript lockfile's packages (production, then development). Installed paths come from Composer's `vendor-dir` and `node_modules`.
- Package names normalize to guideline directory names through a fixed table (`laravel/framework` → `laravel`, `@inertiajs/vue3` → `inertia-vue`, `livewire/flux` → `fluxui-free`, …); other names lowercase and replace `@`, `/`, `_` (`acme/my_pkg` → `acme-my-pkg`).
- First-party packages are the `laravel/*` Composer scope, `livewire/livewire`, `livewire/flux`, `livewire/flux-pro`, `livewire/volt`, `inertiajs/inertia-laravel`, `pestphp/pest`, `phpunit/phpunit`, and the `@inertiajs/*`, `@laravel/*`, `laravel-echo`, `laravel-precognition`, `laravel-vite-plugin` NPM names. Their `resources/boost/guidelines/core` replaces the bundled `<name>/core` guideline, and their `resources/boost/skills` add skills keyed by the normalized name.
- Third-party packages are direct, non-first-party dependencies with `resources/boost/guidelines` or `resources/boost/skills`. Transitive dependencies never contribute third-party assets.
- `laravel/boost` and `laravel/sail` are never package-guideline sources. `laravel/mcp` and `livewire/livewire` contribute only when direct. `pestphp/pest` suppresses `phpunit/phpunit`; `livewire/flux-pro` suppresses `livewire/flux`.
- Third-party guideline keys are `<full package name>/<relative path without .md/.blade.php>`; bundled versioned guidelines use `<name>/v<major>` (or `<name>/v<major>/<file>`). Application guidelines below `.ai/guidelines` whose relative path equals a key (or the bundled relative path) replace that source in place; a Blade variant wins over Markdown. Remaining application guidelines are keyed `.ai/<relative path>` and are composed before the base guidelines.
- Skill composition order is: core, integration, bundled per-package (root then installed-major), first-party vendor skills, third-party vendor skills (package inventory order), package-specific application skills (`.ai/<name>/skill` then `.ai/<name>/<major>/skill`), and explicit application skills (`.ai/skills/*`). Later entries with the same frontmatter `name` win. A `SKILL.blade.php` entry point wins over `SKILL.md` in the same folder.

## Rendering surface

Blade templates receive `$assist` (a `GuidelineAssist`). The v2.10.4 assets use: `artisanCommand`, `artisan`, `hasPackage`, `appPath`, `inertia()` (`gte`, `hasFormComponent`, `hasFormComponentResets`, `pagesDirectory`), `binCommand`, `sailBinaryPath`, `composerCommand`, `nodePackageManagerCommand`, `nodePackageManager`, `project->php()` / `project->js()` (Roster ecosystems), `hasMcpEnabled`, `supportsPintAgentFormatter`, `hasSkillsEnabled`, `hasComposerScript`, `hasCloudEnabled`, `enums`, and `enumContents`.

Directives and conventions: `@boostsnippet('name', 'lang') … @endboostsnippet` (fenced example with an HTML comment label), `@scoped([...globs]) … @endscoped` (ignored inside fenced code and when escaped as `@@scoped`), Blade escapes `@{{`, `@{!!`, `@@`, and `@verbatim`. Before rendering, backticks, `<?php`, `@volt`, `@endvolt`, `@can`, `@include`, `@props`, and `<x-`/`</x-` component tags are masked so examples are not compiled. Plain `.md` files are never evaluated.

Boost skips a guideline or skill that fails to render or parse. Afterburner intentionally fails the command instead (stage 05).

## Output

Boost writes one `<laravel-boost-guidelines>` block per guideline file and copies skills to per-agent skill roots. Its `boost.json` records `agents`, `guidelines`, `skills` (published names), `packages`, `mcp`, `sail`, `cloud`, and `nightwatch`; it records no file hashes or destination manifest. Boost agent identifiers are `claude_code`, `codex`, `cursor`, `pi`, and `opencode` among others; their default skill roots are `.claude/skills`, `.agents/skills`, `.cursor/skills`, `.pi/skills`, and `.agents/skills`.

## Rules

Boost's index command reads top-level `.ai/rules/*.md` plus managed `.ai/rules/boost/*.md`, excludes `index.md`, sorts rows by project-relative path, warns about files without `paths`, and fails on lines beginning with seven `<` or `>` characters. Its index text asks the agent to read "that rule file" for the matching row; Afterburner's index instead requires reading every matching rule. Boost silently ignores rule files whose YAML cannot be parsed; Afterburner reports them.

## Read-only queries

Boost's `database-query` tool allows `SELECT`, `SHOW`, `EXPLAIN`, `DESCRIBE`, `DESC`, `WITH … SELECT`, `VALUES`, and `TABLE`; rejects stacked statements, write keywords at statement boundaries, write-targeting `EXPLAIN`, any `INTO`, and MySQL executable comments; ignores literals and comments while scanning; runs inside a read-only transaction (`SET TRANSACTION READ ONLY` for MySQL/MariaDB/PostgreSQL, `PRAGMA query_only` for SQLite) that is always rolled back; and prefixes table references when the connection has a table prefix.
