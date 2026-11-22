---
description: "Publish the core skills that replace Boost's MCP tools: tool compatibility, documentation, rules, inspection, and debugging."
status: active
---

# Core operational skills

## Purpose

Discoverable agent skills replace Boost's MCP tools. Skills choose existing commands and the agent's own tools; only the rule index and the protected query command add behavior.

## Dependencies

[05 — Rendering](05-blade-rendering-and-validation.md), [06 — Rules](06-project-rules.md), and [07 — Read-only queries](07-read-only-database-queries.md), published through stage 03's pipeline. Stage 09 composes these skills with the ecosystem presets.

## Configuration

Core skills are selected by `core`, `boost`, or `afterburner`; `none` contributes none of them. Application overrides and dependency switches work as for any other source. There is no MCP-tool configuration registry.

## Core catalog

The core catalog is exactly the Afterburner skills that replace Boost's MCP tools, one folder per skill in `resources/core/skills`. It contains no guidelines, no standing instructions, and no other skills, and none of its content is copied or adapted from Boost: a test fails if any eight-word run of text in `resources/core` also appears in the Boost snapshot. Skill entry points are Blade templates, so their commands come from the `$assist` command builders and follow the application's environment (for example `vendor/bin/sail artisan …` under Sail).

| Skill identity | Responsibility | Replaces |
| --- | --- | --- |
| `afterburner-boost-tool-compatibility` | Recognize requests for Boost tools and map them to available workflows | every Boost MCP tool name |
| `afterburner-documentation` | Identify installed versions and relevant primary documentation; use the agent's own research tools | `search-docs` |
| `afterburner-project-rules` | Find applicable rules, record/update explicitly requested durable knowledge, and regenerate the index | `record-rule` |
| `afterburner-application-inspection` | Application/package information, routes, database structure/queries, URLs, and small runtime inspections | `application-info`, `database-query`, `database-schema`, `database-connections`, `get-absolute-url`, `tinker`, and the Boost 1.x listing tools |
| `afterburner-debugging` | Inspect application logs/errors and use current computer-use tools for browser issues | `read-log-entries`, `last-error`, `browser-logs` |

These are ordinary skill identities. Core skills come after the Boost snapshot's own skills in the precedence order (stage 09), so an application skill with the same name replaces a core skill; there is no separate override system. Convention inference is not a core skill: the `boost` and `afterburner` presets publish the snapshot's `infer-conventions` skill, adapted (stage 09) to record approved conventions with this skill's recording steps.

## Tool compatibility mapping

The compatibility skill's description names Laravel Boost MCP tools and says it applies when guidelines, skills, or documentation tell the agent to call one, listing every mapped tool name. Its body maps each name to a skill or concrete command:

| Boost request | Supported route |
| --- | --- |
| `search-docs` | Documentation skill and the agent's research tools |
| `record-rule` | Project-rule skill, direct Markdown editing, then `afterburner:index-rules` |
| `database-query` | `afterburner:database:query`, with the tool's `database` parameter as `--connection` |
| `database-schema` | `db:show` and `db:table` (with `--database`) |
| `database-connections` | `db:show` and `config:show database.connections` |
| `get-absolute-url` | `tinker --execute` with `url()` or `route()` |
| `tinker` | `tinker --execute` |
| `read-log-entries`, `last-error` | Debugging skill and the configured application log |
| `browser-logs` | Debugging skill and the agent's browser or computer-use tool |
| `application-info` resource (and the Boost 1.x tool) | `about`, `composer show --direct`, `package.json` |
| Boost 1.x `list-routes`, `list-artisan-commands` | `route:list`, `list`, `help` |
| Boost 1.x `get-config`, `list-available-config-keys` | `config:show` and `config/` |
| Boost 1.x `list-available-env-vars` | `.env.example` names only, never `.env` values |

The skill also says Boost MCP prompts are unavailable (upgrades go through the documentation skill), that no Boost tool ever runs, and that an unknown tool must be reported as unavailable rather than replaced with an invented command. The mapping exists only in the skill; Afterburner adds no standing guidance that names tools.

## Workflow requirements

The documentation skill identifies Composer and NPM versions (`composer show --direct`, the lockfile, `about`) before choosing official documentation for the installed major version (`https://laravel.com/docs/<major>.x/<topic>`, first-party documentation sites, or the installed package source), and uses the agent's own web or research tools. Without such tools the agent says so, falls back to installed sources, and asks before relying on unverified APIs. Nothing calls Boost's hosted API, caches documentation, or hard-codes a Laravel version.

The inspection skill uses `about`, `composer show --direct`, `route:list`, `list`/`help`, `config:show`, `db:show`, `db:table`, and `migrate:status`; `afterburner:database:query` for reads (JSON output, read-only, choosing the named connection); and `tinker --execute` with `url()`/`route()` for URLs and small runtime checks, noting that `APP_URL` alone is not equivalent. There is no per-expression command.

The debugging skill locates the configured log channel and file (`config:show logging.default`/`logging.channels`, `single` vs `daily` files, non-file channels), reads recent entries and the last `.ERROR:` entry, mentions `pail` only when `laravel/pail` is installed, reproduces failures with tests or Tinker, and uses the agent's own browser or computer-use tool for browser issues without installing a collector. When a research or browser capability is missing, the skills tell the agent to say so and follow application instructions; they never ask it to fabricate observations.

Tinker is assumed for the workflows that use it; there is no detection, fallback bootstrap, or dependency.

## Project-rule skill

The project-rules skill's description tells the agent to use it before planning or editing files, as well as when asked to record something. It reads `.ai/rules/index.md`, follows every rule whose row matches a path in scope, and searches the rule files for task terms. It records only durable knowledge the user asked to remember (or approved), picks the most specific glob, reuses a file whose `paths` contains exactly that glob, preserves existing entries and paths, appends a `##` entry with an imperative note, runs the index command and fixes what it reports, and leaves the generated index and `.ai/rules/boost` alone. It never calls `record-rule` and references no skill outside the core catalog.

Because `core` adds no standing guidance, rule discovery under `core` relies on that skill being activated. Under `boost` and `afterburner`, the snapshot's own guidance additionally tells agents to consult the rules index.

## Testing

`tests/Feature/CoreSkillsTest.php` publishes through the public install command and checks that `core`, `boost`, and `afterburner` publish all five skills with valid frontmatter, substantial descriptions, and fully rendered bodies; that `core` publishes exactly those skills and no guideline document even when Laravel ecosystem packages are installed; that `resources/core` contains only the five skill entry points and shares no eight-word text sequence with the Boost snapshot; that `none` publishes no bundled skills; that every Boost tool name appears in the compatibility skill's description and body but not in standing guidance; that every skill a core skill references exists and every `php artisan` command it names is registered (Tinker and Pail are the assumed/conditional exceptions); that nine representative scenarios (search docs, remember a controller convention, find the rules for a file, inspect routes, query a named database, database structure, runtime URL, browser error, last error) lead to the expected command or tool; that `infer-conventions` is absent from `core` and published once, from the snapshot, under `boost`; Sail-aware rendering; conditional Pail guidance; an application override of a core skill; and removal of core output when switching to `none`.

The rendered skills were also reviewed by reading them in full. These checks show the instructions are complete and point at real commands; they cannot prove that every external agent will activate or obey a skill.

## Acceptance criteria

- [x] Core publishes only the usable operational skills that replace Boost's MCP tools.
- [x] The mapping description makes Boost tool requests discoverable and all supported targets exist.
- [x] Documentation/browser workflows use the agent's own capabilities.
- [x] Inspection prefers existing commands and preserves protected query behavior.
- [x] Rule recording follows the agreed durable-knowledge workflow without MCP.
- [x] Application overrides can replace core skills through normal precedence.
- [x] No new Tinker dependency, fallback bootstrap, collector, hosted search client, or redundant wrapper is added.

## Dev plan

1. Author the core catalog and focused descriptions.
2. Add mapping, inspection, and debugging instructions against actual command contracts.
3. Add the rule-finding and recording workflow.
4. Publish/test core and review the representative agent scenarios.

## Out of scope

Ecosystem-specific development advice and convention inference belong to the `boost` and `afterburner` presets. There is no agent runtime, LLM execution in tests, or guarantee of external tool availability.
