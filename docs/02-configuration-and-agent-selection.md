---
description: "Define application preferences, independent package switches, agent selection, and separate generated tracking."
status: active
---

# Configuration and agent selection

## Purpose

Installation choices are explicit and repeatable. Configuration is the shared input to composition/publication, and the same file carries the state needed to identify generated output.

## Dependencies

[01 — Package foundation](01-package-foundation-and-test-harness.md). Preferences are exercised through `afterburner:install`; there is no configuration-only product command.

## Configuration

The application-root `afterburner.json` holds these fields:

| Field | Meaning | Default |
| --- | --- | --- |
| `base` | `boost`, `afterburner`, `core`, or `none` | `afterburner` for fresh installation; `boost` for explicit replacement |
| `agents` | Explicit selected agent identifiers | No guessed selection for fresh noninteractive installation |
| `packages` | Per-dependency guideline/skill switches | Both content types enabled for eligible dependencies |
| `rules.scoped_guidelines` | Extract scoped guidelines into managed rules | `false` |
| `integrations.sail` | Render commands through Laravel Sail | Sail is installed (binary plus Compose file) and active (`LARAVEL_SAIL=1`) |
| `integrations.cloud` | Include the Laravel Cloud deployment integration skill | `false` |
| `enforce_tests` | Include the bundled test-enforcement guideline | The application has at least six detectable test cases and a test runner |
| `purpose` | A sentence describing the application, rendered into the bundled foundation guideline | None |
| `published` | Generated publication state, separate from user preferences | Empty managed inventory |

`integrations`, `enforce_tests`, and `purpose` are optional rendering preferences that correspond to Boost's `sail`/`cloud` choices and its `boost.enforce_tests` and `boost.purpose` settings; they let generated commands and conditional guidance reflect the actual application rather than a guess, and they are imported during replacement.

Canonical agent identifiers are `claude`, `codex`, `cursor`, `pi`, and `opencode`, shown in prompts as Claude Code, Codex, Cursor, Pi, and OpenCode. Migration translates Boost's identifiers (`claude_code` → `claude`; the others are identical). Selected agents are deduplicated and saved in canonical order, so `codex,claude,codex` is stored as `["claude", "codex"]`. Unknown identifiers and incorrect field types fail with the field path and the offending value, for example ``afterburner.json has an invalid `agents[1]`: "copilot" is not a supported agent``.

A package entry such as `"vendor/package": {"guidelines": false, "skills": true}` disables only that dependency's guidelines. An omitted switch is `true`, an empty object enables both, and unsupported switch names (for example a misspelled `guideline`) are rejected rather than ignored. Entries use the full Composer/NPM package identity. Application sources remain eligible when a dependency is excluded.

`published` records project-relative managed locations: `guidelines` lists the documents that contain an Afterburner managed block, `skills` maps each skill directory to the skill names published there, and `rules` lists generated rule files. It never grants ownership of entire guideline files or of sibling content. Paths must be project-relative (no absolute paths or `..` segments) and skill names must be single path segments; anything else is rejected as invalid configuration. Publication state lives only in this object, separate from preferences; there is no second state file.

## Selection and persistence

`afterburner:install` accepts `--agents=claude,codex` (a comma-separated supported list) and `--base=<preset>`. Explicit command-line choices are saved.

When no agent selection is saved or given, an interactive install offers all supported agents with project-detected ones pre-selected. Detection looks only at project-local markers (`.claude` or `CLAUDE.md`, `.codex`, `.cursor`, `.pi`, `.opencode` or `opencode.json[c]`); it never inspects global installations and never authorizes writing an agent's files. When no preset is saved or given, an interactive install offers the four presets with the computed default selected. Interactive choices are saved. Choices that are already saved are reused without prompting; they change through `--agents`, `--base`, or editing `afterburner.json`.

A fresh noninteractive installation without saved agents or `--agents` fails before publication with a message naming `--agents` and the `agents` field. An explicitly saved empty list selects no agents, is reused as-is, and is never replaced by detection. A blank command-line token (as in `--agents=claude,,codex`) is rejected rather than treated as an agent.

An explicit saved, interactive, or command-line preset wins over a computed default. The computed default is `afterburner`, or `boost` when replacement is explicitly requested with `--replace-boost`; a computed default is used for that run but not saved. Finding `boost.json`, a Boost guideline block, or an installed `laravel/boost` package changes neither the default nor authorizes migration.

Saving preserves unknown and unrelated keys (including nested values and empty objects) and existing generated tracking; only the changed fields are replaced, and the file is rewritten atomically. Malformed JSON, a non-object document, or an invalid known field fails the command and leaves the file byte-for-byte unchanged. A missing file is a normal first run.

Preference persistence is coordinated with publication: preferences and the `published` inventory are written together only after the selected assets pass the validation gate (stage 03 onward), so an invalid asset never commits a new inventory.

## Errors

Schema errors name the field and the invalid value or category. Unreadable or malformed existing configuration is reported and never overwritten. An install or update without a saved or explicit agent selection does not adopt machine-specific defaults.

## Testing

`tests/Feature/ConfigurationTest.php` runs `afterburner:install` in fixture applications and covers: missing configuration without agents; command-line selection, canonical ordering, and deduplication; unknown, blank, and Boost-style command-line identifiers; configured agents reused without prompts or rewrites; an explicit empty list surviving detected agent markers; first interactive setup with a detected agent; prompting only for unsaved choices; the unsaved fresh default; every preset saved and then reused; an unknown preset option; the replacement default with saved and explicit presets winning; Boost files not changing the default; malformed JSON, non-object documents, wrong types for every known field, unsupported switch names, and unsafe published paths, each leaving the file unchanged; partial package switches; and preservation of unrelated keys, nested unknown values, empty objects, and publication state.

The effect of package switches on publication is asserted in stage 04, and validation-gate persistence in stages 03, 05, and 10. Tests assert public configuration results and errors, not private configuration classes.

## Acceptance criteria

- [x] One JSON file distinguishes user choices from generated tracking.
- [x] All four presets and five agent identifiers are validated.
- [x] Fresh and explicit replacement defaults differ as agreed; explicit choices win.
- [x] Interactive choices persist; fresh noninteractive selection is explicit.
- [x] Package content switches default independently to enabled.
- [x] Invalid existing configuration is preserved and reported, not reset.
- [x] Preference changes preserve unrelated keys and generated ownership records.

## Dev plan

1. Implement validated reading and effective preference resolution.
2. Add prompts, explicit agent-list input, and successful persistence through installation.
3. Add fixture scenarios for defaults, saved state, and invalid inputs.
4. Reuse this configuration contract in every following stage.

## Out of scope

Full agent output generation is stage 03. Snapshot contents, replacement execution, and cleanup mechanics are specified separately.
