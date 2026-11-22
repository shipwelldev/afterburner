# Afterburner

Afterburner gives coding agents application-specific guidance for Laravel projects. It publishes guidelines and skills for Claude Code, Codex, Cursor, Pi, and OpenCode, keeps project rules discoverable, and adds a protected read-only database query command. It replaces Laravel Boost's asset publication and workflows without running an MCP server, and packages that ship Boost assets keep working unchanged.

## Requirements and support

Afterburner supports the PHP and Laravel versions that receive upstream bug fixes. As of 2026-11-21 the tested matrix is:

| Component | Versions |
| --- | --- |
| PHP | 8.4, 8.5, 8.6 |
| Laravel | 13 (13.40 or newer) |
| `afterburner:database:query` engines | SQLite, MySQL 8.4, MariaDB 11.4, PostgreSQL 17 |

The matrix follows the bug-fix support windows of PHP and Laravel. Dropping a PHP or Laravel version that a release supported requires a new Afterburner major release.

## Installation

```bash
composer require shipwelldev/afterburner --dev
php artisan afterburner:install
```

The first interactive run asks which agents to publish for (agents detected from project files such as `.claude`, `CLAUDE.md`, `.codex`, `.cursor`, `.pi`, or `opencode.json` are pre-selected) and which base preset to use, then publishes and saves your choices in `afterburner.json`. Later runs reuse the saved choices without asking.

In CI or other noninteractive environments, choose the agents explicitly:

```bash
php artisan afterburner:install --agents=claude,codex --no-interaction
php artisan afterburner:install --agents=cursor --base=core --no-interaction
```

A noninteractive install without saved agents or `--agents` fails rather than guessing.

## Commands

| Command | Purpose |
| --- | --- |
| `afterburner:install` | Configure, publish, and save choices. Options: `--agents=`, `--base=`, `--replace-boost` |
| `afterburner:update` | Refresh everything from the saved choices; never prompts |
| `afterburner:index-rules` | Validate `.ai/rules` and regenerate `.ai/rules/index.md` |
| `afterburner:database:query` | Run a read-only SQL query and print the rows as JSON |

`boost:install`, `boost:update`, and `boost:index-rules` run the same operations once Laravel Boost is no longer installed, so existing scripts keep working. While Boost is installed those names remain Boost's own commands.

## Configuration

Everything Afterburner needs is in the application-root `afterburner.json`:

```json afterburner.json
{
    "base": "afterburner",
    "agents": ["claude", "codex"],
    "purpose": "Scheduling and billing for veterinary clinics.",
    "packages": {
        "acme/admin-kit": {"guidelines": false},
        "@acme/ui": {"skills": false}
    },
    "rules": {"scoped_guidelines": false},
    "integrations": {"sail": true, "cloud": false},
    "enforce_tests": true
}
```

| Field | Meaning | Default |
| --- | --- | --- |
| `base` | Base preset: `boost`, `afterburner`, `core`, or `none` | `afterburner`; `boost` for an explicit Boost replacement |
| `agents` | Agents to publish for: `claude`, `codex`, `cursor`, `pi`, `opencode` | none; must be chosen |
| `purpose` | A sentence describing the application, added to the `boost`/`afterburner` foundation guideline | none |
| `packages` | Per-package `guidelines` / `skills` switches | both enabled for every eligible package |
| `rules.scoped_guidelines` | Move `@scoped` guidance into managed rule files | `false` |
| `integrations.sail` | Write commands as `vendor/bin/sail …` | on when Sail is installed and active |
| `integrations.cloud` | Include the Laravel Cloud deployment skill | `false` |
| `enforce_tests` | Include the test-enforcement guideline | on when the app has a test runner and at least six tests |
| `published` | Output Afterburner manages; written by Afterburner | — |

An explicit choice always wins over a default: a saved `base`, an interactive choice, or `--base`. Defaults are used without being saved. An empty `agents` list is a valid choice that publishes nothing and removes previously managed output on the next update.

Afterburner never rewrites the file to defaults: malformed JSON or an invalid field makes the command fail with the field name and leaves the file unchanged, and keys Afterburner does not know are preserved.

## Presets

| `base` | Afterburner-supplied content |
| --- | --- |
| `boost` | Core skills, plus Laravel Boost v2.10.4's guidelines and skills, adapted to read `afterburner.json` and with Boost's MCP-server content removed |
| `afterburner` | Core skills, plus Afterburner's curated copy of that adapted guidance (currently also without Boost's Laravel Cloud promotion) |
| `core` | Core skills only: no guidelines and no other skills |
| `none` | Nothing bundled; only your application's and packages' own content |

Application and package content is published under every preset. Ecosystem guidance follows your installed packages and major versions (for example Laravel 11 or 12 guidance, Livewire 2–4 and Inertia 1–3 skills, Tailwind 3 or 4). All bundled content ships with the package; installing and updating never download anything.

## Where output goes

| Agent | Guidelines | Skills |
| --- | --- | --- |
| Claude Code | `CLAUDE.md` | `.claude/skills` |
| Codex, Cursor, Pi, OpenCode | `AGENTS.md` | `.agents/skills` |

Guidelines are written into one managed section per file:

```text
<afterburner-guidelines>
=== .ai/architecture rules ===

Your guidance…

</afterburner-guidelines>
```

Text before and after the section is yours and is never changed. Afterburner records what it publishes in `afterburner.json` and only replaces or removes that output: skill folders it did not publish are left alone (a same-named conflict stops publication instead of overwriting), and a shared directory stays while any selected agent still uses it.

Generated files are disposable. Edits to `CLAUDE.md`'s managed section or to published skills are replaced on the next update; make lasting changes in your `.ai` sources instead.

## Your own guidance

| Source | Location |
| --- | --- |
| Guidelines | `.ai/guidelines/**/*.md` or `*.blade.php` |
| Skills | `.ai/skills/<folder>/SKILL.md` or `SKILL.blade.php`, with supporting files alongside |
| Skills for one installed package | `.ai/<package-name>/skill/<folder>` and `.ai/<package-name>/<major>/skill/<folder>` (for example `.ai/livewire/3/skill/…`) |
| Package-provided assets | `resources/boost/guidelines` and `resources/boost/skills` inside any direct Composer or NPM dependency |

A skill needs YAML frontmatter with `name` and `description`; the `name` is its identity. Blade templates can use Boost's `$assist` helpers (for example `{{ $assist->artisanCommand('test') }}`), `@boostsnippet`, and `@scoped`.

To replace a bundled or package guideline, put a file with the same identity under `.ai/guidelines`: `.ai/guidelines/foundation.md` replaces the foundation guideline, `.ai/guidelines/laravel/12/core.md` replaces the Laravel 12 guidance, and `.ai/guidelines/acme/admin-kit/core.md` replaces the `core` guideline of the `acme/admin-kit` package. A skill in `.ai/skills` with the same `name` replaces any bundled or package skill.

Direct Composer and NPM dependencies' assets are included by default. Switch off a package's guidelines or skills under `packages`; your own `.ai` content for that package stays published. Invalid selected content (a template error, missing skill metadata, unreadable files) stops publication with the file's path, and existing output stays as it was; content from a switched-off package is never read.

## Keeping it current

Run `php artisan afterburner:update` after dependency changes, or let Composer do it:

```json composer.json
{
    "scripts": {
        "post-update-cmd": [
            "@php artisan afterburner:update --ansi"
        ]
    }
}
```

Existing `@php artisan boost:update --ansi` hooks keep working after you replace Boost.

## Core skills

The core skills replace Boost's MCP tools. The `core`, `boost`, and `afterburner` presets publish them, and they are the only bundled content of `core`:

| Skill | What it teaches |
| --- | --- |
| `afterburner-boost-tool-compatibility` | What to do when instructions mention a Boost MCP tool (`search-docs`, `database-query`, `record-rule`, …) |
| `afterburner-documentation` | Finding docs for the installed versions with the agent's own research tools |
| `afterburner-project-rules` | Finding applicable rules and recording new ones |
| `afterburner-application-inspection` | Versions, routes, config, database structure, read-only queries, and URLs with existing commands |
| `afterburner-debugging` | Reading the application log and investigating browser errors |

The skills point agents at existing tools: Artisan commands, Tinker (assumed to be installed; Afterburner does not add it), the agent's own web research, and its browser or computer-use tools when it has them. When a capability is missing, the skills tell the agent to say so rather than guess. Publishing a skill does not guarantee that a particular agent activates or follows it.

## Project rules

Project rules are durable conventions scoped to paths. Each is a Markdown file directly in `.ai/rules`:

```markdown
---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Thin controllers
Move business logic into action classes.
```

After adding or editing rules, regenerate the index:

```bash
php artisan afterburner:index-rules
```

The index (`.ai/rules/index.md`) maps globs to rule files. The `afterburner-project-rules` skill tells agents to read every rule matching the files they touch before planning or editing, and the Boost guidance in the `boost` and `afterburner` presets points them to the index as well. Files without valid `paths` are reported and left out; unresolved merge-conflict markers make the command fail without touching the index. Ask an agent to remember a convention and the `afterburner-project-rules` skill edits these files and runs the command.

With `rules.scoped_guidelines` enabled, `@scoped([...])` sections of published guidelines move into generated files in `.ai/rules/boost` instead of standing guidance. Those files are managed by Afterburner; your own rule files are never changed.

## Read-only queries

```bash
php artisan afterburner:database:query "SELECT id, email FROM users ORDER BY id DESC LIMIT 5"
php artisan afterburner:database:query "SELECT count(*) AS total FROM orders" --connection=reporting
```

The command prints the rows as a JSON array (or `[]`), or exits with status 1 and a message on stderr. It accepts `SELECT`, `SHOW`, `EXPLAIN`, `DESCRIBE`, `WITH … SELECT`, `VALUES`, and `TABLE` queries, rejects writes, stacked statements, writable CTEs, `EXPLAIN` of writes, `INTO`, and MySQL executable comments, and runs every query in a database-enforced read-only transaction that is always rolled back. It uses your existing connections and never changes their configuration.

## Replacing Laravel Boost

While Boost is still installed, run:

```bash
php artisan afterburner:install --replace-boost
```

(an interactive `afterburner:install` also offers it, defaulting to no). The replacement:

1. Captures Boost's agents (`claude_code` becomes `claude`), Sail and Cloud choices, `enforce_tests` and scoped-rule settings, the skills Boost tracked, its guideline sections, its generated rules, and its project MCP files. Choices already in `afterburner.json` or given as options win. The preset defaults to `boost`.
2. Validates the complete result before changing anything. If agents cannot be determined noninteractively (for example Boost was set up only for unsupported agents), it stops and asks for `--agents`.
3. Runs `composer remove laravel/boost` (with `--dev` for a development requirement). Set `AFTERBURNER_COMPOSER` to use a specific Composer binary.
4. Finishes in a fresh process: replaces Boost's guideline sections in place, adopts Boost's published skills and rules, publishes, removes the `laravel-boost` MCP server from project files (`.mcp.json`, `.cursor/mcp.json`, `.codex/config.toml`, `opencode.json[c]`, and the other agents' project files) while keeping other servers and comments, and saves your choices.

Afterburner only edits project files. Remove a globally configured `laravel-boost` server (for example in `~/.cursor/mcp.json`, `~/.codex/config.toml`, or Claude Code's user scope) yourself. Output for agents Afterburner does not support, `boost.json`, and `config/boost.php` are left in place and reported.

This is not a transaction. If Composer cannot remove Boost (for example another package requires it), the replacement stops before publishing or touching MCP files, but Composer may already have edited `composer.json` or `composer.lock`. If something fails after Boost is removed, fix the reported problem and run `php artisan afterburner:install --replace-boost` again; it resumes from the recorded state instead of removing Boost again.

## For maintainers

`resources/boost` is the attributed Laravel Boost v2.10.4 snapshot in Boost's own layout, with its license, a `PROVENANCE.json` of the released file hashes, and `ADAPTATIONS.patch`: Afterburner's changes that make it read `afterburner.json` instead of Boost's config and replace Boost MCP tool instructions with Afterburner's skills. `resources/afterburner` is the separately curated copy built on the adapted snapshot; `resources/afterburner/CURATION.md` lists every change from it. To move to a new Boost release:

```bash
bin/refresh-boost-snapshot /path/to/laravel-boost-checkout --tag=v2.10.5
```

The script reapplies `ADAPTATIONS.patch` to the new release and only then replaces `resources/boost`; if the patch no longer applies it changes nothing and lists the conflicting files. After adjusting adapted files by hand, record the new patch with `bin/refresh-boost-snapshot /path/to/laravel-boost-checkout --record-adaptations`. Port wanted changes to the curated copy by hand and update `tests/Fixtures/boost-baseline.json`.

Run the tests with `vendor/bin/pest --testsuite=Feature` (command tests) and `vendor/bin/pest --testsuite=Process` (real Artisan and Composer processes; the first run installs a Laravel host with Composer). The database engine tests run when `AFTERBURNER_TEST_{MYSQL,MARIADB,PGSQL}_{HOST,PORT,DATABASE,USERNAME,PASSWORD}` are set; otherwise they are reported as skipped. The specifications live in `docs/`.

## License

Afterburner is released under the MIT License. Bundled guidance copied or adapted from Laravel Boost is copyright Taylor Otwell under the MIT License; see `resources/boost/LICENSE.md`.
