---
description: "Explicitly migrate a Boost application, remove its dependency, and clean only recognized project-local output and MCP entries."
status: active
---

# Explicit Boost replacement

## Purpose

Switching an existing application from Boost is a concrete, repeatable operation. It removes Boost through Composer while preserving applicable choices and user-owned content.

## Dependencies

[10 — Complete lifecycle](10-installation-and-updates.md) and all earlier publication and compatibility behavior. Tests use real host-application processes in addition to the command harness.

## Configuration

Replacement applies only to the invocation that requests it; finding Boost files never saves an auto-migrate setting. Explicit Afterburner choices (on the command line or in `afterburner.json`) win over imported ones. The continuation context in `storage/framework/afterburner-replacement.json` is operational migration state: it carries the resolved choices, imported preferences, adoption list, and MCP locations across the Composer removal, and it is deleted when the replacement finishes. It is never a second publication inventory.

## Entry and authorization

Replacement runs only through `afterburner:install --replace-boost` or by answering yes when an interactive install finds Boost (an installed `laravel/boost` or a `boost.json`) and asks "Laravel Boost is present. Replace it with Afterburner now?", which defaults to no. Declining runs a normal install, which still refuses to claim Boost's untracked output. The flag or answer authorizes the whole operation: capture, settings import, Composer removal when Boost is installed, targeted project-local MCP cleanup, and managed republication. No second approval is asked.

While both packages are installed, Boost keeps its own `boost:install`, `boost:update`, and `boost:index-rules` commands (Afterburner registers those names only when Boost's service provider is absent), so the replacement is started with `afterburner:install`. After removal the three names run the Afterburner operations.

Agents come from `--agents`, then saved `afterburner.json` agents, then Boost's translated agents, then (interactively) a prompt. When none can be resolved noninteractively, for example because Boost was configured only for unsupported agents, the command fails before anything changes and names `--agents`.

## Capture and preflight

While Boost is still present the command captures:

- `boost.json`: agents (`claude_code` → `claude`; `codex`, `cursor`, `pi`, `opencode` unchanged; others reported as unsupported), tracked skill names, `sail`, and `cloud` (Boost's default of on when it published skills). Boost's opt-in `packages` list is reported but not imported, because Afterburner includes direct dependency assets by default; a `guidelines: false` choice is reported too. A malformed `boost.json` stops the replacement.
- `config('boost')`, including any published `config/boost.php`: `purpose`, `enforce_tests`, `rules.scoped_guidelines`, and per-agent `guidelines_path`, `skills_path`, and `mcp_config_path` for supported agents. Paths outside the application are reported for manual cleanup and never touched.
- Boost guideline blocks (`<laravel-boost-guidelines>` … `</laravel-boost-guidelines>` on their own lines, with LF or CRLF line endings) in `CLAUDE.md`, `AGENTS.md`, and configured guideline paths. Malformed Boost delimiters stop the replacement.
- Boost-tracked skills present in the supported agents' skill roots (`.claude/skills`, `.agents/skills`, `.cursor/skills`, `.pi/skills`, or configured paths), including symlinks Boost created for custom skills. Only names listed in `boost.json` are attributed to Boost; Boost records no hashes or manifest.
- Generated rule files in `.ai/rules/boost`, and the project-local MCP locations.

The preset is `--base`, then a saved preset, then (interactively) a prompt defaulting to `boost`, then `boost`; the effective preset is saved, so later updates keep it. Imported `sail`, `cloud`, `purpose`, `enforce_tests`, and `scoped_guidelines` values are set only where `afterburner.json` has no value.

The complete selection, including adoption of Boost's output, is planned and validated before any file, dependency, or MCP entry changes; an invalid selected asset stops the replacement with Boost still installed. Only then is the context written, so a Composer callback that runs `boost:update` during removal uses the intended choices.

## Composer and process sequencing

The command checks `vendor/composer/installed.json` for `laravel/boost` and `composer.json` for where it is required, then runs `composer remove laravel/boost --no-interaction --no-progress`, adding `--dev` for a development requirement, as an external process with its output passed through. Composer is `AFTERBURNER_COMPOSER` when set (an executable, or a `.phar`/`.php` run with PHP), then the `COMPOSER_BINARY` Composer exports to scripts, then `composer` on the `PATH`. Composer constraints are never edited by hand and no `replace` claim is added.

If Boost is still installed afterwards, whatever Composer's exit code (real Composer exits 2 with "Removal failed, laravel/boost is still present, it may be required by another package"), the replacement stops: the context is deleted, nothing is published, no MCP entry is edited, and the message points to `composer why laravel/boost` and warns that Composer may already have changed `composer.json` or `composer.lock`. There is no rollback. If Boost is gone but Composer still failed (for example a script error), the context is kept and running the command again finishes the replacement. When Boost is already absent, the Composer step is skipped and the replacement finishes in the same process with the captured state.

After a successful removal the command finishes in a fresh `php artisan afterburner:install --replace-boost` process, because removal changed files the original process loaded. With a context present and Boost absent, that command finishes the recorded replacement instead of capturing or removing again; this is also the corrective repeat after any failed finish, which reports that Boost was removed and keeps the context. A `boost:update` or `afterburner:update` that runs while a context exists (such as a Composer `post-update-cmd` callback) skips when Boost is still installed, and otherwise publishes with the recorded choices, keeps the context, and says the replacement is still in progress.

## Targeted MCP cleanup

Only an entry named `laravel-boost` whose launch is `artisan boost:mcp` through PHP (any PHP binary path), Sail (`vendor/bin/sail artisan boost:mcp`), or WSL (`wsl.exe <php> <artisan> boost:mcp`) is removed. A `laravel-boost` entry with any other launch, or another server that runs `boost:mcp`, is kept and reported for manual review.

The known project containers are `mcpServers` in `.mcp.json`, `.cursor/mcp.json`, `.pi/mcp.json`, `.junie/mcp/mcp.json`, `.kiro/settings/mcp.json`, and `.agents/mcp_config.json`; `servers` in `.vscode/mcp.json`; `amp.mcpServers` in `.amp/settings.json`; `context_servers` in `.zed/settings.json`; OpenCode's `mcp` local-command entries in `opencode.json` and `opencode.jsonc`; the `[mcp_servers.laravel-boost]` table (with its subtables) in `.codex/config.toml` and `.grok/config.toml`; and configured project-local `mcp_config_path` files.

JSON and JSONC files are edited with a comment-aware scanner that removes only the entry and its separating comma, so other servers, comments, trailing commas, and formatting are unchanged. TOML edits remove only the table lines, leaving comments that precede the next table. A file is deleted only when nothing but Boost's defaults would remain and it has no comments. Syntax that cannot be edited safely (for example an inline TOML table) is reported and left alone, and a cleanup write failure fails the command with the context kept.

Global agent configuration is never edited; the command always reminds the user to remove a globally added `laravel-boost` server themselves.

## Output adoption and republication

Captured Boost blocks are converted to the managed Afterburner block in memory before planning, so the new guidance replaces Boost's block in place and surrounding text is preserved, including removal of the `===` separator line Boost adds before an appended block. A document already holding an Afterburner block simply loses the Boost block, and a captured document no selected agent uses loses its Boost block (and is deleted if nothing else remains). Adopted skills and rules count as previously published output: still-selected names are replaced, others are removed (including Boost copies in old agent roots such as `.cursor/skills`), and untracked siblings are untouched. Publication then goes through the same validated writer as normal installs.

Application `.ai` sources, user rules, untracked skills, `boost.json`, `config/boost.php`, and unrelated configuration are preserved; the command notes that the two Boost configuration files are no longer used. Output for unsupported agents (for example `.github/copilot-instructions.md`) is left unchanged and reported. Existing Composer scripts calling `boost:update` work afterwards without being rewritten.

## Testing

`tests/Feature/BoostReplacementTest.php` seeds an application as Boost leaves it (blocks with manual text around them, tracked and untracked skills including a symlinked custom skill, Boost rules, MCP entries in JSON, JSONC with comments, TOML with comments and subtables, a WSL entry, and a global Cursor config). It covers an already removed Boost, the separator line (including a CRLF document), skill and rule adoption, targeted MCP removal with preserved content, eight launch shapes, servers other than `laravel-boost`, unsupported TOML syntax, unsupported agents, saved and command-line choices winning, imported configuration with custom project paths and an outside path, unresolved agents, three preflight failures with an unchanged tree and no Composer call, Composer failure and blocked removal with `--dev`, a production requirement without `--dev`, an update during a pending replacement, a repeat after a failed finish, and the interactive yes/no offer.

`tests/Process/BoostReplacementProcessTest.php` uses host projects with an isolated vendor tree, a fake installed Boost, and `tests/Fixtures/bin/fake-composer.php`: a removal whose `post-update-cmd` runs `boost:update --ansi`, finished in a fresh process; the aliases after removal; and a blocked removal. `tests/Process/RealComposerReplacementTest.php` installs the real `laravel/boost` v2.10.4 with Composer, lets Boost's own installer publish its output, checks that `boost:install` still belongs to Boost, and then replaces it with a real `composer remove`; it also adds a local package that requires Boost and checks that the replacement stops with Boost's output and MCP entries untouched.

## Acceptance criteria

- [x] Only an explicit choice/flag triggers replacement, and it authorizes the complete specified operation.
- [x] Choices and ownership are captured before removal; explicit Afterburner choices win.
- [x] Replacement defaults to `boost` and resolves required agents before dependency changes.
- [x] Composer removal respects requirement location, scripts, failures, and real dependency constraints.
- [x] Final publication uses a fresh process; reentrant automation does not corrupt intended choices.
- [x] Known project-local Boost entries/output are migrated without deleting unrelated content.
- [x] Ambiguous/global cleanup is reported honestly and left untouched.
- [x] User sources, rules, and untracked assets survive the transition.
- [x] Repeating corrective replacement is possible and compatibility aliases work after removal.

## Dev plan

1. Implement capture/preflight and fixture import/adoption behavior.
2. Implement Composer subprocess execution and fresh-process continuation.
3. Add targeted format-preserving MCP cleanup and shared publication finalization.
4. Run failure/retry, coexistence, callbacks, and real Composer integration scenarios.

## Out of scope

There is no silent migration during normal installation, automatic removal of other dependencies, global agent edit, broad deletion of old source configuration, or transactional rollback promise.
