---
description: "Validate the integrated package, supported-runtime matrix, distributed assets, and application-facing documentation."
status: active
---

# Release validation and documentation

## Purpose

The staged work is verified as a usable package rather than a collection of individually passing fragments, with accurate instructions and support and provenance records for the initial release candidate.

## Dependencies

All [earlier stages](00-project-overview.md#implementation-order), particularly [10 — Lifecycle](10-installation-and-updates.md) and [11 — Replacement](11-explicit-boost-replacement.md). Their regression tests were written with each stage; this stage consolidates them.

## Configuration

No new application settings are introduced. Supported dependency constraints live in `composer.json`, runtime and driver combinations in `.github/workflows/tests.yml`, and snapshot provenance in `resources/boost/PROVENANCE.json`, `resources/afterburner/PROVENANCE.json`, and `tests/Fixtures/boost-baseline.json`. User-facing defaults are those of the earlier specs.

## Integrated scenarios

`tests/Feature/LifecycleTest.php` takes one fixture application with application overrides and Composer and NPM package assets through a fresh install with the default `afterburner` preset and explicit agents, then updates after adding and removing packages, changing the Laravel major version, changing package switches, and switching presets and agents, checking the resulting files and cleanup at every step. `tests/Feature/BundledPresetsTest.php` covers every preset, and every agent is exercised alone and together, including shared-root deselection, without any agent's global installation.

Explicit replacement of a provisioned Boost application without an MCP server is covered in `tests/Process/BoostReplacementProcessTest.php` and, with the real `laravel/boost` v2.10.4 and real Composer, in `tests/Process/RealComposerReplacementTest.php`: the default `boost` preset, imported and explicit preferences, targeted MCP cleanup, fresh-process completion, preserved manual content, and the existing `boost:update --ansi` Composer hook.

Literal `none`, the core-skills-only `core` preset, rule indexing and extraction round trips, protected named-connection reads, and failures before publication for invalid selected content are covered by the earlier stage suites through the same public commands. Manual checks with real agents can validate skill descriptions and workflows but are not part of the automated suite.

## Support matrix

On 2026-11-21 the bug-fix-supported runtimes are Laravel 13 and PHP 8.4, 8.5, and 8.6; Laravel 12 and PHP 8.3 are security-only. `composer.json` requires `php: ^8.4` and `illuminate/*: ^13.40`. Laravel releases before 13.40.0 carry security advisories that Composer blocks by default, so 13.40.0 is the lowest release a `--prefer-lowest` install resolves; the constraint does not claim the untested earlier releases.

Verified combinations on 2026-11-21:

| PHP | Laravel | Suites |
| --- | --- | --- |
| 8.4.26 | 13.43.0 | Feature (all database engines) and Process |
| 8.5.12 | 13.43.0 | Feature (all database engines) and Process |
| 8.6.0 | 13.43.0 | Feature (all database engines) and Process |
| 8.5.12 | 13.40.0 (lowest dependencies) | Feature |

CI runs PHP 8.4, 8.5, and 8.6 with the highest dependencies and PHP 8.4 with the lowest, provisioning MySQL 8.4, MariaDB 11.4, and PostgreSQL 17 as services. The feature suite runs with `--fail-on-skipped`, so a skipped database engine cannot count as a pass. CI runs for every pull request and every push to `main`; a newer push to a pull request cancels its superseded run. A `Tests passed` job succeeds only when every test and lint job succeeded, and it is the single check that `.github/rulesets/require-tests.json` requires before the default branch can be updated, so the matrix can change without editing the ruleset. The official `php:8.6-cli` image lacks `mbstring`, so local 8.4 and 8.6 runs used images with `mbstring`, `pdo_mysql`, and `pdo_pgsql` added.

Dropping a PHP or Laravel version that a release supported requires an Afterburner major release; the README states this policy next to the dated matrix.

## Distribution and dependency checks

`.gitattributes` (`export-ignore` for `git archive` dists) and `composer.json`'s `archive.exclude` (for `composer archive`) keep the consumer package to `src`, `resources`, `composer.json`, `README.md`, and `LICENSE.md`. `tests/Process/DistributionTest.php` builds the archive with `composer archive`, checks that it contains every snapshot file listed in the provenance, the adaptations patch, the licenses, provenance and curation records, the five core skills, and no tests, docs, maintainer scripts, CI files, or vendor files, then installs it into a fresh Laravel host as copied files (not a symlink) and publishes the `boost` preset in a real Artisan process with every HTTP proxy pointing at a closed port.

The same test checks that the installed dependency tree contains no `laravel/boost`, `laravel/mcp`, or `laravel/tinker`. Runtime dependencies are `illuminate/*`, `laravel/prompts`, `laravel/roster` (the package inventory Boost itself uses), `symfony/process` (Composer and fresh-process execution), and `symfony/yaml` (frontmatter); each is version-constrained in `composer.json`, and no shell or network requirement exists beyond Composer for an explicit replacement.

## User documentation

`README.md` documents installation and the canonical commands and aliases; `afterburner.json` fields and defaults; the four presets; agent destinations; package switches, source conventions, and override identities; generated-output ownership and why generated edits are disposable; the fresh `afterburner` and replacement `boost` defaults and explicit-choice precedence; noninteractive agent selection and saved-choice updates with a Composer hook; the core skills, the Boost tool mapping, the research/browser capability assumptions, and the Tinker assumption; project rules with direct editing plus indexing, user versus managed rules, and scoped extraction; the read-only query contract; explicit replacement while Boost is installed, including Composer and fresh-process behavior, captured choices, project-only MCP cleanup, manual global cleanup, and honest failure and retry limits; maintainer snapshot refresh without touching the curated copy; the dated support matrix and policy; and that publishing a skill does not guarantee an agent uses it.

## Testing

The whole suite runs in CI (`Feature` with database services, `Process` with real Composer). `tests/Feature/DocumentationTest.php` installs every `afterburner.json` example in the README through the real command, checks that the README's Composer hook calls a registered command, that every `php artisan` command and option the README shows exists, that relative Markdown links in the README and every spec resolve, and that relative references inside published skills (links and `references/` or `rules/` mentions) resolve in the published output. Assertions focus on external results, preserved content, exit behavior, and public contracts.

## Acceptance criteria

- [x] Every earlier feature's acceptance criteria/tests pass in the integrated package.
- [x] Fresh install, updates, all presets, all agents, overrides/exclusions, rules, queries, and explicit replacement work together.
- [x] A consumer distribution contains all required assets and attribution and publishes without fetching upstream content.
- [x] Runtime/driver support claims correspond to executed compatible integration jobs.
- [x] Documentation covers the actual public command/configuration contracts and ownership/default/failure behavior.
- [x] No hidden MCP server, hosted search client, browser collector, or niche Tinker fallback has appeared.
- [x] All implementation specs are reconciled with working behavior before being marked active.

## Dev plan

1. Run integrated and distribution-host scenarios, resolving cross-stage regressions.
2. Verify and record runtime/driver support and upstream asset provenance.
3. Write/review user and maintainer documentation against actual commands/output.
4. Reconcile spec status with implemented behavior and prepare a release candidate.

## Out of scope

Automated Packagist publishing, issue-tracker publishing, and release-hosting infrastructure are outside this implementation sequence.
