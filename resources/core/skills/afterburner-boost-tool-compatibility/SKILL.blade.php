---
name: afterburner-boost-tool-compatibility
description: "Use whenever you are asked to use a Laravel Boost MCP tool, or when guidelines, skills, or documentation tell you to call one (search-docs, database-query, database-schema, database-connections, get-absolute-url, tinker, read-log-entries, last-error, browser-logs, record-rule, application-info, list-routes, list-artisan-commands, get-config, list-available-config-keys, list-available-env-vars). This project has no Boost MCP server; this skill maps each tool to the command or skill that replaces it."
---
# Laravel Boost tool compatibility

This application uses Afterburner instead of Laravel Boost. There is no Boost MCP server, so Boost tools cannot be called and no tool call will succeed. Guidelines and skills written for Boost still mention those tools. When you meet one, do the equivalent work with the route below, and never claim a Boost tool ran.

## Tool map

| Boost tool or resource | What to do instead |
| --- | --- |
| `search-docs` | Load the `afterburner-documentation` skill. Identify the installed versions, then search the official documentation for those versions with your own web search or research tools. |
| `record-rule` | Load the `afterburner-project-rules` skill. Edit the rule Markdown in `.ai/rules` directly, then run `{{ $assist->artisanCommand('afterburner:index-rules') }}`. |
| `database-query` | Run `{{ $assist->artisanCommand('afterburner:database:query "SELECT …"') }}`. The tool's `database` parameter is the `--connection=<name>` option. Only read-only queries run. See the `afterburner-application-inspection` skill. |
| `database-schema` | Run `{{ $assist->artisanCommand('db:show') }}` for an overview and `{{ $assist->artisanCommand('db:table <table>') }}` for one table; add `--database=<connection>` for another connection. |
| `database-connections` | Run `{{ $assist->artisanCommand('db:show') }}` for the default connection, or `{{ $assist->artisanCommand('config:show database.connections') }}` to list the configured connection names. |
| `get-absolute-url` | Generate the URL with Laravel itself: `{{ $assist->artisanCommand("tinker --execute 'echo url(\"/dashboard\");'") }}` or `{{ $assist->artisanCommand("tinker --execute 'echo route(\"dashboard\");'") }}`. Reading `APP_URL` alone is not equivalent. |
| `tinker` | Run `{{ $assist->artisanCommand("tinker --execute '…'") }}`, wrapping the PHP in single quotes so the shell leaves `$` variables alone. |
| `read-log-entries`, `last-error` | Load the `afterburner-debugging` skill and read the configured application log. |
| `browser-logs` | Load the `afterburner-debugging` skill and use your own browser or computer-use tool to read the browser console. |
| `application-info` (resource, or the older tool) | Run `{{ $assist->artisanCommand('about') }}` and `{{ $assist->composerCommand('show --direct') }}`, and read `package.json`. |
| `list-routes` | Run `{{ $assist->artisanCommand('route:list') }}`. |
| `list-artisan-commands` | Run `{{ $assist->artisanCommand('list') }}`, and `{{ $assist->artisanCommand('help <command>') }}` for options. |
| `get-config`, `list-available-config-keys` | Run `{{ $assist->artisanCommand('config:show <file or key>') }}`, or read the files in `config/`. |
| `list-available-env-vars` | Read `.env.example` for variable names. Do not print values from `.env`; they can be secrets. |

Boost MCP prompts (such as the `upgrade-*` and code-simplifier prompts) are not available either. For an upgrade, use the `afterburner-documentation` skill to read the official upgrade guide for the target version.

## Unknown tools

If an instruction names a Boost tool that is not in the table, say that the tool is not available in this project, do not invent a command for it, and follow the application's own guidelines or ask the user how they want to proceed.
