---
description: "Add a connection-aware Artisan query command preserving Boost-compatible read-only behavior."
status: active
---

# Read-only database queries

## Purpose

The one database execution capability ordinary Tinker access does not preserve is query inspection with Boost-compatible read-only protections. Schema inspection uses existing commands; there is no parallel database tool suite.

## Dependencies

[01 — Foundation and test harness](01-package-foundation-and-test-harness.md). No agent skill is needed to invoke the command; stage 08 teaches agents to use it. Preset selection never affects command registration, and the command works without `afterburner.json`.

## Public command contract

`afterburner:database:query <query> [--connection=<name>]` takes the SQL as its required `query` argument. Omitting `--connection` uses Laravel's configured default connection. The compatibility skill maps the old tool's `database` parameter to `--connection`.

On success the selected rows are printed to stdout as a pretty-printed JSON array (`[]` for an empty result) with nothing else on stdout, and the command exits 0. Values are those Laravel's `select()` returns, encoded with `json_encode` (slashes and Unicode unescaped, zero fractions preserved); there is no second type system. A result that cannot be represented as JSON (for example raw binary) fails with a hint to select it through an encoding function.

A blank query, a rejected query, an unknown connection, an unsupported driver, or an execution error exits 1 with a diagnostic on stderr (`Query rejected: …` or `Query failed: …`) and no stdout output. Laravel's exception messages identify the connection and SQL but never the password; the command never prints connection configuration. A missing `query` argument is rejected by Artisan's argument validation.

## Read-only execution

Queries first pass the structural validation from the pinned Boost `database-query` tool, scanning the query with string literals and comments blanked out. Literals and comments are recognized the way the connection's engine reads them, because a comment or literal the validator misreads could hide code the engine runs (for example MySQL's `SELECT 1--1 INTO OUTFILE …`, where `--` is subtraction):

| Driver | Comments and quoted text |
| --- | --- |
| `sqlite` | `--` and `/* … */` comments; `'…'`, `"…"`, `` `…` ``, and `[…]` quoting |
| `mysql`, `mariadb` | `--` comments only before whitespace or a control character, `#` comments, and `/* … */` comments; `'…'`, `"…"`, and `` `…` `` quoting with backslash escapes |
| `pgsql` | `--` comments and nesting `/* … */` comments; `'…'`, `E'…'`, `$tag$…$tag$`, and `"…"` quoting |

Where a server mode changes how text is read (MySQL and MariaDB `NO_BACKSLASH_ESCAPES` and `ANSI_QUOTES`, PostgreSQL `standard_conforming_strings`), the query must pass under every possible reading. A connection is resolved, without connecting, before validation so its driver is known; another driver is checked under every supported engine's readings and then refused at execution.


- Allowed statement forms: `SELECT`, `SHOW`, `EXPLAIN`, `DESCRIBE`, `DESC`, `WITH … SELECT`, `VALUES`, and `TABLE`. The first keyword may be followed directly by `(`, so `SELECT(1)` is allowed.
- Rejected: stacked statements (a single trailing semicolon is fine), write keywords at any statement boundary (start, `(`, `)`, `;`) including writable CTEs and subqueries, `EXPLAIN` targeting a write (with `ANALYZE`, `VERBOSE`, `QUERY PLAN`, `FORMAT`, or option lists), any `INTO`, and MySQL and MariaDB executable `/*! … */` and `/*M! … */` comments.
- `REPLACE(...)` and `INSERT(...)` function calls and write keywords inside literals or comments are allowed.

Every accepted query then runs inside a database-enforced read-only transaction that is always rolled back:

| Driver | Enforcement |
| --- | --- |
| `sqlite` | `PRAGMA query_only = ON` inside the transaction; the connection's previous `query_only` value is restored afterwards |
| `mysql`, `mariadb` | `SET TRANSACTION READ ONLY` before the transaction begins (it applies to that transaction only) |
| `pgsql` | `SET TRANSACTION READ ONLY` inside the transaction |

Rollback and state restoration run on both success and error, so the connection is left outside a transaction and usable for writes. Other drivers are refused rather than run with rollback alone. Writes the validator cannot see, such as a stored function that inserts rows, are blocked by the engine.

When the connection has a table prefix, table references after `FROM`, `JOIN`, `INTO`, `UPDATE`, `TABLE`, and a leading `DESCRIBE`/`DESC` are prefixed, skipping literals and comments as the engine reads them by default, already-prefixed names, and CTE names. The command never changes saved connection configuration or the default connection.

## Configuration

The command uses the application's existing Laravel database connections and default. There are no new credentials, connection registry, write-enable flag, or persistent query server. Application guidelines can name the intended connection; the command enforces its read-only contract regardless.

## Testing

`tests/Feature/DatabaseQueryTest.php` runs the command against disposable SQLite fixture databases: rows from the default connection, a named connection reading different data without changing the default, an empty result, native value encoding, the allowed read forms (including literals and comments containing write keywords), eighteen rejected write and bypass shapes with data and schema compared afterwards, engine-specific comment and quoting bypasses for MySQL, MariaDB, and PostgreSQL (including each server-mode reading) rejected before connecting, blank and missing queries, unknown connections and invalid SQL without success-shaped output, transaction and `query_only` restoration after success and failure followed by a write, preservation of a pre-existing `query_only = ON`, table prefixes alongside literals, comments, prefixed names, and CTEs, refusal of an unsupported driver without exposing its password, and independence from presets and configuration.

`tests/Feature/DatabaseEnginesTest.php` runs against real MySQL, MariaDB, and PostgreSQL servers configured through `AFTERBURNER_TEST_{MYSQL,MARIADB,PGSQL}_{HOST,PORT,DATABASE,USERNAME,PASSWORD}`: rows and empty results, rejected writes, engine syntax the validator must read as the engine does (MySQL and MariaDB `1--1`, backslash escapes, and `#` comments; PostgreSQL dollar quoting, escape strings, nested comments, and the `#>>` operator), a write hidden in a stored function blocked by the engine, the connection remaining usable for writes and transactions afterwards, and table prefixes with engine-specific literal escaping. An engine without configuration is reported as skipped, and CI runs the feature suite with `--fail-on-skipped` so a skipped engine cannot count as passing. `tests/Process/DatabaseQueryProcessTest.php` checks in a real Artisan process that stdout carries only JSON and failures go to stderr with exit code 1.

The declared driver matrix is SQLite, MySQL 8.4, MariaDB 11.4, and PostgreSQL 17; CI provisions those engines as services. On 2026-11-21 the suite passed locally against disposable `mysql:8.4`, `mariadb:11.4`, and `postgres:17` containers.

## Acceptance criteria

- [x] The command runs independently of preset selection and without MCP.
- [x] Default and named connections return usable JSON rows and correct exit behavior.
- [x] Writes and representative disguised write forms are rejected without persisted changes.
- [x] Driver execution controls, rollback/unwind, and connection-state restoration are exercised.
- [x] Compatible prefixed-table reads do not rewrite literal data.
- [x] The command does not change global connection settings or expose credentials.
- [x] Supported-engine claims are backed by real database integration checks.

## Dev plan

1. Specify input/output behavior and implement default/named connection execution.
2. Adapt the compatible validation and driver-specific read-only execution logic without MCP types.
3. Add command-level read, rejection, cleanup, and prefix scenarios.
4. Exercise the declared driver matrix before release and document it for the inspection skill.

## Out of scope

There is no write-query mode, migration runner, database browser, credential manager, schema wrapper command, or general PHP execution command.
