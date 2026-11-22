---
description: "Create the Laravel package skeleton, command registrations, and isolated application test harness."
status: active
---

# Package foundation and test harness

## Purpose

Establish a real installable Laravel package and the public boundary used to test every later stage. This stage produces working package discovery and command help, not simulated implementation of future commands.

## Dependencies

Read [the overview](00-project-overview.md). No earlier implementation stage is required.

## Package behavior

- The Composer package identity is `shipwelldev/afterburner`, with PSR-4 namespace `Shipwell\Afterburner\` and the Laravel-discoverable `Shipwell\Afterburner\AfterburnerServiceProvider`.
- The provider registers `afterburner:install`, `afterburner:update`, `afterburner:index-rules`, and `afterburner:database:query` through normal Laravel console integration.
- It registers `boost:install`, `boost:update`, and `boost:index-rules` as compatibility entry points. Each is a subclass of the corresponding Afterburner command that only renames the signature, so both names run the same operation and accept the same options. While Laravel Boost itself is still installed (its service provider class exists), those three names are left to Boost and the replacement is started with `afterburner:install` ([stage 11](11-explicit-boost-replacement.md)). No MCP server, `boost:mcp`, or other Boost endpoint is registered.
- Provider boot registers commands and nothing else: it does not publish assets, read or write configuration, invoke Composer, fetch content, start processes, or modify routes or middleware. Those operations happen only through the public commands.
- Command discovery and help work in a host without `laravel/boost` installed. While a command's behavior belonged to a later stage, invoking it reported the development-stage limitation and failed without writing output; each later stage replaces that placeholder with the real operation.
- Runtime dependencies are limited to actual package needs: `illuminate/*` components, `laravel/prompts`, `laravel/roster` (the package inventory Boost itself uses), `symfony/process`, and `symfony/yaml`. The package does not depend on Boost, `laravel/mcp`, or Tinker.

## Runtime and development dependencies

Composer constraints follow the overview's rolling bug-fix support policy. On 2026-11-21, Laravel 13 is the only Laravel release receiving bug fixes (Laravel 12's bug-fix window ended in August 2026), and PHP 8.4, 8.5, and 8.6 (released 2026-11-19) receive bug fixes while PHP 8.3 is security-only. The package therefore requires `php: ^8.4` and `illuminate/*: ^13.40`. The Laravel floor is 13.40.0 because earlier Laravel 13 releases carry security advisories that Composer blocks by default, so 13.40.0 is the lowest release a `--prefer-lowest` install resolves and tests; anything lower would be an untested claim.

The development tools are Orchestra Testbench 11 (the Laravel 13 harness), Pest 5 on PHPUnit 13, and Laravel Pint. Only one test framework is used. `composer.json` declares these dependencies; `composer.lock` is not committed because this is a library.

The tested matrix is recorded in `.github/workflows/tests.yml` (PHP 8.4, 8.5, 8.6 × Laravel 13, plus a lowest-dependencies job). The full suite was also run locally against Laravel 13.43.0 on PHP 8.4.26, 8.5.12, and 8.6.0, and against the lowest resolvable dependencies (Laravel 13.40.0) on PHP 8.5.12. The official `php:8.6-cli` image omits `mbstring`, which Laravel requires, so local 8.6 runs use an image with that extension added.

Tinker is assumed when an application's workflow uses it; it is not a package dependency.

## Configuration

This stage introduces no application configuration keys. Stage 02 owns the application JSON contract; provider boot never creates that file.

## Compatibility baseline

The released compatibility baseline is Laravel Boost `v2.10.4`, commit `9cd5e7d81300b5dee4c8b1619ec4b36f4a6367fd`, with its package inventory from `laravel/roster` `v1.0.0`. `tests/Fixtures/boost-baseline.json` records it for fixtures, and [the Boost compatibility notes](boost-compatibility-notes.md) record the discovery, rendering, rule, and query evidence that later stages derive their cases from. Planning's main-branch checkout is supporting evidence only. This stage does not add Boost as a dependency or ship the preset catalog; stage 09 packages the matching assets.

## Test harness

`tests/TestCase.php` boots the package with Testbench inside a fresh fixture application for every test. `tests/Support/FixtureApplication.php` creates the temporary application root and provides helpers for application assets, skills, `afterburner.json`, Composer and NPM dependency metadata (manifest, lockfile, and installed files), agent files, and whole-tree snapshots used to prove that output is unchanged. `tests/Support/IsolatedEnvironment.php` points `HOME` and `XDG_CONFIG_HOME` at a temporary directory and clears Sail/Composer process markers for each test. The test configuration uses array cache/session drivers and an in-memory SQLite default connection.

`tests/Support/HostProject.php` supplies whole-process tests: it builds a real Laravel 13 host with Composer, installing this package from a path repository, and runs `php artisan` in separate processes. The Composer-installed template is cached per package/runtime combination in the system temporary directory; each test gets its own application root (sharing only the template's vendor directory), its own `HOME`, and in-memory caches. Composer runs with an isolated `COMPOSER_HOME` and reuses only the download cache.

The `Feature` test suite holds command tests in the Testbench harness; the `Process` suite holds host-process tests. Later specs reuse this harness rather than create separate internal test boundaries.

## Testing

`tests/Feature/PackageFoundationTest.php` runs Artisan's `list` and `help` to verify the four canonical commands and three compatibility entry points are discoverable without Boost (and that no `boost:mcp` exists), checks the query command's documented arguments, verifies that rebooting and listing/help leave the fixture filesystem unchanged and add no Afterburner routes or middleware, and verifies the declared runtime dependencies.

`tests/Process/HostDiscoveryTest.php` installs the package into a real host with Composer, verifies Composer's installed package metadata and Laravel's generated package manifest, runs `list`/`help` in real Artisan processes without changing the host's files, and compares the host's routes with the package discovered and with it excluded through `dont-discover`.

Tests assert observable registration, help, and clean boot rather than provider method calls, and no test-only command exists.

## Acceptance criteria

- [x] Composer identifies the package correctly and Laravel discovers its provider.
- [x] Public command names and compatibility entry points are visible without Boost installed.
- [x] Boot/help has no publication, Composer, network, or browser-collection side effect.
- [x] A released compatibility baseline is recorded before discovery/rendering work begins.
- [x] Tests can run in an isolated fixture application and as a subprocess where needed.
- [x] A supported-runtime CI baseline runs the initial integration suite.
- [x] No Boost runtime dependency, MCP server, or newly required Tinker dependency is introduced.

## Dev plan

1. Establish Composer metadata, provider, console registration, and development tooling.
2. Record the released compatibility baseline and build reusable fixture application/subprocess helpers.
3. Add command registration/clean-boot tests and supported-runtime CI.
4. Document which feature behaviors are supplied by later specs; mark active only for this stage's completed behavior.

## Out of scope

Asset discovery, actual publication, query execution, project-rule indexing, and dependency removal are owned by later stages.
