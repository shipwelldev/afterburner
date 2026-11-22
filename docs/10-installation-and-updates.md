---
description: "Complete interactive and automated installation, saved-choice refresh, aliases, and managed lifecycle cleanup."
status: active
---

# Installation and updates

## Purpose

The previously tested capabilities form the complete application-facing lifecycle. Installation, update, and their compatibility entry points share one composition, validation, and publication path.

## Dependencies

[02 — Configuration](02-configuration-and-agent-selection.md) through [09 — Bundled presets](09-bundled-assets-and-presets.md).

## Configuration

The commands use the single `afterburner.json` model plus the `--agents`, `--base`, and `--replace-boost` install options; there is no separate update configuration. Every invocation reads `afterburner.json` afresh, so edits take effect on the next run.

## Installation

`afterburner:install` resolves explicit input and saved preferences, offers first-run interactive setup, discovers eligible sources, renders and validates the complete selection, publishes, and then persists the choices and publication state together.

First interactive setup offers all supported agents (project-detected ones pre-selected) and the four presets. Later runs reuse saved choices without prompting; `--agents`, `--base`, or editing `afterburner.json` change them. Prompts appear only for a person: not with `--no-interaction` and not while running inside a Composer script (`COMPOSER_DEV_MODE` is set). A fresh noninteractive installation without saved agents or `--agents` fails before publication; agents are never chosen from the machine.

The fresh default preset is `afterburner`, and `boost` is the default only for explicit replacement; neither is saved unless chosen. Detecting `boost.json`, a Boost guideline block, Boost MCP entries, or an installed `laravel/boost` never triggers migration, dependency removal, settings import, or MCP cleanup. `--replace-boost` is the entry point for the explicit replacement operation specified in [stage 11](11-explicit-boost-replacement.md), and it is the only path into that operation.

## Update and compatibility entry points

`afterburner:update` refreshes from saved preferences and the current application and dependency inventory, re-evaluating installed versions, newly added direct dependencies (included by default), package switches, and overrides. It never prompts, so it is safe in Composer automation. Without saved agents it fails with an instruction to run the install command.

Once Boost is absent, `boost:install` and `boost:update` run the same operations as `afterburner:install` and `afterburner:update` with the same options and output, and `boost:index-rules` matches `afterburner:index-rules`; while Boost is installed those names remain Boost's own commands. Standard console options such as `--no-interaction` and `--ansi` work as usual. Historical Boost-only options (`--mcp`, `--guidelines`, `--skills`, `--discover`, `--no-discover`, `--ignore-skills`) are rejected by Artisan as unknown options rather than launching hidden tooling. Composer hooks that call `@php artisan boost:update --ansi` work once choices are saved in `afterburner.json`, including after an explicit replacement imports them.

## Successful refresh

Output is computed once per distinct destination, and local, dependency, core, and ecosystem content follow the same rules. Each refresh reconciles stale managed output for removed sources and packages, changed major versions, package switches, preset changes, and agent selection changes, while keeping manual text, custom skills, user rules, and any shared destination another selected agent still needs.

`none` publishes only eligible application and dependency content. An explicit empty agent list removes all previously managed output without detecting agents. Custom source files are never deleted, and generated files are not treated as durable overrides.

The command records ownership from the actual publication while preserving preferences and unrelated settings. Repeating an unchanged refresh produces a byte-identical tree. The command reports the selected agents and preset, the number of guidelines and skills per destination, any scoped rules, and removals.

## Validation and failure handling

The shared prepublication gate applies: an invalid selected asset or configuration leaves generated output and publication state unchanged, and excluded invalid sources do not fail the run. Nothing is silently omitted from the selected context.

A filesystem failure after validation returns a nonzero exit code and names the failed operation; publication state then records what Afterburner owns, and the new choices are not saved. There is no transaction across external operations; migration has its own phase and failure contract.

## Testing

`tests/Feature/LifecycleTest.php` seeds manual text in both guideline documents, custom skills in both skill roots, a user rule, application sources, and dependency content, then walks one application through a failed first noninteractive run, a fresh install with the default preset, an idempotent update, a newly added NPM package, a Laravel major change, package removal, a preset change, deselecting Claude, deselecting one and then the last shared agent, and an explicit empty selection, checking user content after each stage. It also covers default and explicit preset precedence, every agent destination with full presets, every package switch on update, an invalid selected asset preserving prior output and tracking while an excluded invalid asset is ignored, identical results through `boost:install` and `boost:update`, rejection of historical Boost-only options, no prompts inside Composer scripts, and that ordinary install and update leave `boost.json`, Boost MCP entries, Composer files, Boost-published skills, and the Boost guideline block untouched. First interactive setup, saved settings, and explicit preset precedence are also covered in `tests/Feature/ConfigurationTest.php`.

`tests/Process/ComposerHookTest.php` runs a real `composer run-script post-update-cmd` whose hook is `@php artisan boost:update --ansi` in a provisioned host without Boost, checks the published output, the ANSI output, and a following idempotent update, and checks that the hook fails clearly when no agents were ever chosen.

## Acceptance criteria

- [x] One integrated path serves installation, update, and compatibility entry points.
- [x] First-run interactive and noninteractive selection match the configuration contract.
- [x] Fresh/replacement defaults and explicit-choice precedence remain correct.
- [x] Updates include newly eligible package assets and respect exclusions/overrides.
- [x] Refresh/cleanup is idempotent, destination-aware, and preserves unrelated content.
- [x] Selected-input failures preserve prior output and tracking.
- [x] Ordinary installation does not migrate Boost or remove MCP configuration.
- [x] Existing update automation works through the alias after Boost removal.

## Dev plan

1. Consolidate the full source/render/validate/publish orchestration behind both commands.
2. Complete prompts, saved-choice behavior, and automated invocation behavior.
3. Exercise lifecycle transitions across presets, packages, and agents.
4. Add the explicit replacement entry point without embedding migration logic into normal refresh.

## Out of scope

There is no background watcher, install-time fetch mechanism, scheduled update service, or automatic Boost migration. Stage 11 owns the explicit dependency transition.
