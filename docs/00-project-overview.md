# Afterburner — project overview

Afterburner is a Laravel Composer package named `shipwelldev/afterburner`. It replaces Laravel Boost's asset publication and development workflows without running an MCP server. Existing packages shipping Boost assets must remain usable without publishing a second set of assets for Afterburner.

This document is the shared context for the feature specs. The package implements every numbered spec, each feature document's status records that, and the test suite described below runs against the implementation. User-facing documentation is in the repository's `README.md`.

## Product boundaries

Afterburner publishes guidelines and skills, maintains project-rule discovery, and supplies only the Artisan commands needed to fill meaningful gaps in existing tooling. Skills teach agents to use existing commands, research tools, and computer-use tools.

Network access is allowed. Offline equivalents of hosted documentation or an agent's browser capabilities are not required. Do not replace the old MCP server with hidden MCP execution wrappers, a new documentation service, or injected browser-log collection.

Assume Tinker exists for the skills using it. Applications that remove Tinker can add their own guidelines or skills. Do not add a fallback bootstrap workflow or require Tinker as a new dependency merely to accommodate that case.

## Domain language

Use [the project glossary](../GLOSSARY.md):

- A **guideline** is standing application context for an agent.
- A **skill** is named guidance loaded for a relevant task.
- A **project rule** is durable application-specific knowledge with a scope.
- A **base preset** selects Afterburner-supplied content separately from application/dependency content.
- A **core skill** teaches an operational workflow such as recording rules or finding documentation.

## Architectural decisions

Two decisions from the design discussion apply across every stage:

1. Deliver capabilities through published guidance, skills, existing tools, and necessary Artisan commands without an MCP server or runtime dependency on Boost.
2. Preserve package-authored Boost prose. A discoverable compatibility skill maps Boost tool requests to supported skills or commands; do not rewrite arbitrary package prose or place the entire mapping into standing context. Afterburner's own bundled copies of Boost's guidance are the exception: they are adapted through a recorded patch to use Afterburner's configuration and skills instead of Boost's configuration and MCP tools.

The previous ADR files are not part of this repository. These decisions remain authoritative here.

## Base presets and defaults

| Preset | Afterburner-supplied content |
| --- | --- |
| `boost` | Core operational capabilities plus the attributed, released Boost asset snapshot, adapted to Afterburner's configuration without Boost-MCP content |
| `afterburner` | Core capabilities plus a separately maintained copy of that adapted snapshot, initially with only the Laravel Cloud promotion removed |
| `core` | Only Afterburner's core skills, which replace Boost's MCP tools; no other guidance or skills |
| `none` | No bundled guidelines, skills, or bootstrap instructions |

Application and eligible dependency assets remain available under every preset. Commands are installed independently of content selection.

Fresh installs default to `afterburner`. Explicit Boost replacement defaults to `boost`. An explicit saved or interactive preset choice wins over either default.

Further trimming of the curated Afterburner content belongs to the maintainer. Do not apply other proposed removals automatically. Updating the Boost snapshot must not overwrite the curated copy. Installation uses shipped assets, never downloads a snapshot.

## Application-facing interfaces

| Interface | Responsibility |
| --- | --- |
| `afterburner:install` / `boost:install` | Configuration, selection, publication, and explicitly enabled replacement |
| `afterburner:update` / `boost:update` | Refresh using saved choices |
| `afterburner:index-rules` / `boost:index-rules` | Validate and regenerate rule discovery |
| `afterburner:database:query` | Connection-aware queries with Boost-compatible read-only protections |
| `afterburner.json` | User choices and separate generated publication state |

Preserve Boost-compatible application sources under `.ai` and dependency sources under `resources/boost`. Support Markdown, Blade, package/major-version applicability, authoring helpers, and scoped directives. Application content always takes precedence; package-to-package conflicts follow Boost.

Direct Composer and NPM dependency assets publish by default. Users can exclude a package's guidelines, skills, or both while retaining their own overrides.

## Agent outputs

| Selected integration | Guideline document | Skills |
| --- | --- | --- |
| Claude Code | `CLAUDE.md` | `.claude/skills` |
| Codex | `AGENTS.md` | `.agents/skills` |
| Cursor | `AGENTS.md` | `.agents/skills` |
| Pi | `AGENTS.md` | `.agents/skills` |
| OpenCode | `AGENTS.md` | `.agents/skills` |

Publish shared destinations once. Preserve text outside managed guideline sections and untracked skills. Generated skills are disposable outputs; lasting customizations belong in `.ai` sources. Agent deselection must not remove a destination still needed by another selected agent.

## Implementation order

| Stage | Spec | Runnable increment |
| --- | --- | --- |
| 01 | [Package foundation and test harness](01-package-foundation-and-test-harness.md) | Package discovery and public command registration in a fixture application |
| 02 | [Configuration and agent selection](02-configuration-and-agent-selection.md) | Validated, repeatable application choices through installation |
| 03 | [Application asset publication](03-application-asset-publication.md) | Publish local Markdown guidelines and skills with managed ownership |
| 04 | [Dependency discovery and precedence](04-dependency-discovery-and-precedence.md) | Include dependency assets, exclusions, applicability, and application overrides |
| 05 | [Blade rendering and validation](05-blade-rendering-and-validation.md) | Render compatible dynamic assets and validate the complete selection before writing |
| 06 | [Project rules](06-project-rules.md) | Index existing rules and optionally extract scoped guidance |
| 07 | [Read-only database queries](07-read-only-database-queries.md) | Execute protected queries through the public query command |
| 08 | [Core operational skills](08-core-operational-skills.md) | Publish the operational workflows and Boost tool mapping |
| 09 | [Bundled assets and presets](09-bundled-assets-and-presets.md) | Ship both ecosystem content sets and complete all four preset compositions |
| 10 | [Installation and updates](10-installation-and-updates.md) | Complete the interactive/noninteractive lifecycle and refresh behavior |
| 11 | [Explicit Boost replacement](11-explicit-boost-replacement.md) | Remove Boost and migrate settings/output through an explicit operation |
| 12 | [Release validation and documentation](12-release-validation-and-documentation.md) | Verify the integrated package, support matrix, distribution, and user instructions |

Stage 03 establishes the basic publication behavior early so discovery/rendering can be tested through generated files rather than internal APIs. Earlier stages may use `none` and local fixtures before bundled presets exist. They must not claim later behavior works or return fake success for unimplemented operations. Add regressions as each stage is implemented; stage 12 consolidates validation rather than postponing tests until the end.

## Testing contract

The user confirmed one primary product boundary: public Artisan commands executed in fixture Laravel applications, asserting exit behavior, output, configuration, generated files, and database effects. Prefer this boundary over new test-only interfaces or private-method tests.

Use a Laravel package test harness for normal command tests and temporary host projects/subprocesses when real application boot, Composer scripts, or fresh-process migration are relevant. The same public commands remain the test boundary in both cases. No particular test framework or private class layout was selected during planning.

Tests must isolate fixture roots, environment, caches, agent-global locations, and databases. Never depend on a developer's globally installed agents, real home directory, external research services, or production data. Composer-process fixtures can be controlled locally; separately exercise a real provisioned Composer removal before release. Installing test dependencies is distinct from downloading assets during application installation.

A good test checks a user-observable result with representative inputs, not internal calls or a second implementation of discovery/rendering logic. Compare stable generated sections and parsed data, not unrelated framework banner text. Review skills for actual usable instructions; structural tests do not prove that every external agent will activate a skill.

## Failure and ownership principles

Validate selected assets before changing generated output. Invalid selected metadata or rendering failures must fail with a useful source identity and leave previous output intact. Invalid excluded content must not block publication.

Preserve unrelated configuration, manual guideline text, untracked skills, and user-authored rules. Cleanup targets only managed output. Do not describe Composer migration as a transaction: Composer failure stops progression, and a successful removal is followed by a fresh application process.

## Runtime support and provenance

Support PHP and Laravel versions receiving upstream bug fixes. Security-only status or permissive Composer constraints alone do not qualify a version for the maintained matrix. Dropping a previously supported runtime requires an Afterburner major release.

On 2026-11-21 that criterion selects Laravel 13 and PHP 8.4–8.6 (PHP 8.6.0 was released on 2026-11-19; Laravel's published table then stopped at PHP 8.5 while its CI included 8.6). The suite passes on PHP 8.4, 8.5, and 8.6 with Laravel 13.40–13.43; [stage 12](12-release-validation-and-documentation.md) records the verified matrix. Re-verify it for each release rather than treating this dated observation as permanent.

The pinned baseline is the released Boost v2.10.4 (commit `9cd5e7d81300b5dee4c8b1619ec4b36f4a6367fd`); newer main-branch source was only supporting evidence. The shipped snapshot retains Boost's attribution; Afterburner's adaptations to it (Afterburner configuration instead of Boost's, no MCP-specific content) are recorded as a reversible patch, and differences are resolved against the release. [Boost compatibility notes](boost-compatibility-notes.md) record the evidence; they do not override the agreed product contract.

## Project completion criteria

- All numbered feature acceptance criteria are satisfied and relevant command-boundary tests pass.
- All four presets, five agents, source override/exclusion rules, lifecycle cleanup, and explicit replacement work together.
- Existing Boost package assets remain usable without running Boost or an MCP server.
- The read-only query command and rule index provide the agreed additional behaviors; other capabilities use existing tools through skills.
- Distribution contains attributed assets, and installation needs no asset-fetch request.
- Documentation explains defaults, ownership, overrides, migration limits, and support policy accurately.
