---
name: afterburner-application-inspection
description: "Use to inspect this Laravel application: installed versions and packages, routes, configuration, database structure, read-only database queries, absolute URLs, and small runtime checks (replaces Boost's application-info, list-routes, database-schema, database-connections, database-query, get-absolute-url, and tinker tools)."
---
# Inspecting the application

Prefer existing Artisan commands. Each one runs in the application's real environment.

## Application and packages

- `{{ $assist->artisanCommand('about') }}` shows the Laravel and PHP versions, environment, drivers, and cache state. Narrow it with `--only=environment`.
- `{{ $assist->composerCommand('show --direct') }}` lists direct PHP dependencies and versions; `package.json` and its lockfile list JavaScript dependencies.

## Routes and commands

- `{{ $assist->artisanCommand('route:list') }}` lists routes. Narrow it with options such as `--path=admin` or `--name=orders.`, leave out package routes with `--except-vendor`, and add `-v` to include each route's middleware.
- `{{ $assist->artisanCommand('list') }}` lists Artisan commands, and `{{ $assist->artisanCommand('help <command>') }}` shows a command's arguments and options.

## Configuration

`{{ $assist->artisanCommand('config:show app.name') }}` prints a configuration value; pass a file name such as `database` to print the whole file. Read `.env.example` for environment variable names, and do not print values from `.env`.

## Database structure

- `{{ $assist->artisanCommand('db:show') }}` summarizes the default connection: engine, database, and tables. Add `--database=<connection>` for another connection and `--counts` for row counts.
- `{{ $assist->artisanCommand('db:table users') }}` shows one table's columns, indexes, and foreign keys.
- `{{ $assist->artisanCommand('migrate:status') }}` shows which migrations have run.
- `{{ $assist->artisanCommand('config:show database.connections') }}` lists the configured connection names.

## Reading data

Run read-only SQL with the protected query command:

```bash
{{ $assist->artisanCommand('afterburner:database:query "SELECT id, email FROM users ORDER BY id DESC LIMIT 10"') }}
{{ $assist->artisanCommand('afterburner:database:query "SELECT count(*) AS total FROM orders" --connection=reporting') }}
```

It prints the rows as a JSON array and exits nonzero with the reason on stderr when a query is rejected or fails. Only `SELECT`, `SHOW`, `EXPLAIN`, `DESCRIBE`, `WITH … SELECT`, `VALUES`, and `TABLE` queries run, inside a read-only transaction that is always rolled back. Choose the connection the application's guidelines name for the data, and add a `LIMIT` to exploratory queries. Never try to work around a rejected query; ask the user before any change to data.

## URLs and runtime values

Generate URLs with Laravel so the scheme, host, port, and path prefix are correct:

```bash
{{ $assist->artisanCommand("tinker --execute 'echo url(\"/dashboard\");'") }}
{{ $assist->artisanCommand("tinker --execute 'echo route(\"profile.show\", [\"user\" => 1]);'") }}
```

Use `tinker --execute` with single quotes around the PHP for other small runtime checks, such as `config('queue.default')` or `app()->environment()`. Prefer an existing Artisan command when one exists, and do not create or modify records from Tinker without the user's approval; write a test with factories instead.
