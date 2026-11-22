<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\CommandResult;
use Tests\Support\FixtureApplication;

function sqliteConnection(FixtureApplication $fixture, string $name, array $users, string $prefix = ''): void
{
    $path = $fixture->path("database/{$name}.sqlite");
    touch($path);

    config()->set("database.connections.{$name}", [
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => $prefix,
        'foreign_key_constraints' => true,
    ]);

    $connection = DB::connection($name);
    $connection->statement("CREATE TABLE {$prefix}users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, note TEXT)");

    foreach ($users as $index => $user) {
        $connection->insert("INSERT INTO {$prefix}users (id, name, note) VALUES (?, ?, ?)", [$index + 1, $user, null]);
    }
}

/**
 * @return array{rows: int, schema: array<int, mixed>}
 */
function databaseState(string $connection = 'primary'): array
{
    return [
        'rows' => DB::connection($connection)->table('users')->count(),
        'schema' => array_map(fn ($row) => (array) $row, DB::connection($connection)->select('SELECT type, name, sql FROM sqlite_master ORDER BY name')),
    ];
}

function query(string $sql, ?string $connection = null): CommandResult
{
    return runCommand('afterburner:database:query', array_filter(['query' => $sql, '--connection' => $connection]));
}

beforeEach(function () {
    sqliteConnection($this->fixture, 'primary', ['Ada', 'Grace']);
    sqliteConnection($this->fixture, 'secondary', ['Linus']);
    config()->set('database.default', 'primary');
});

it('returns rows as a JSON array from the default connection', function () {
    $result = query('SELECT id, name FROM users ORDER BY id');

    expect($result->successful())->toBeTrue()
        ->and(json_decode($result->output, true, flags: JSON_THROW_ON_ERROR))->toBe([
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Grace'],
        ]);
});

it('reads from a named connection without changing the default', function () {
    $result = query('SELECT name FROM users', 'secondary');

    expect($result->successful())->toBeTrue()
        ->and(json_decode($result->output, true))->toBe([['name' => 'Linus']])
        ->and(config('database.default'))->toBe('primary')
        ->and(DB::getDefaultConnection())->toBe('primary');
});

it('returns an empty array for an empty result', function () {
    $result = query("SELECT * FROM users WHERE name = 'nobody'");

    expect($result->successful())->toBeTrue()
        ->and(trim($result->output))->toBe('[]');
});

it('preserves native values in JSON', function () {
    $result = query("SELECT 1 AS integer, 1.5 AS real, 2.0 AS whole, NULL AS missing, 'naïve/✓' AS text");

    expect(json_decode($result->output, true))->toBe([['integer' => 1, 'real' => 1.5, 'whole' => 2.0, 'missing' => null, 'text' => 'naïve/✓']])
        ->and($result->output)->toContain('"whole": 2.0')->toContain('naïve/✓');
});

it('allows the supported read forms', function (string $sql) {
    expect(query($sql)->successful())->toBeTrue();
})->with([
    'select' => ['SELECT count(*) AS total FROM users'],
    'lowercase with trailing semicolon' => ['select name from users;'],
    'function call' => ['SELECT(1) AS one'],
    'cte' => ['WITH named AS (SELECT name FROM users) SELECT * FROM named'],
    'values' => ['VALUES (1, 2)'],
    'explain' => ['EXPLAIN QUERY PLAN SELECT * FROM users'],
    'replace function' => ["SELECT replace(name, 'a', 'b') AS name FROM users"],
    'write keyword in literal' => ["SELECT 'DELETE FROM users; DROP TABLE users' AS text"],
    'write keyword in comment' => ["SELECT name FROM users -- DROP TABLE users\n"],
    'write keyword in block comment' => ['SELECT /* INSERT INTO users */ name FROM users'],
    'quoted identifier' => ['SELECT "name" FROM "users"'],
    'bracketed identifier with a quote' => ["SELECT name AS [it's] FROM users"],
]);

it('rejects writes and disguised write forms without changing data or schema', function (string $sql, string $reason) {
    $before = databaseState();

    $result = query($sql);

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('Query rejected', $reason)
        ->and(json_decode($result->output))->toBeNull()
        ->and(databaseState())->toBe($before);
})->with([
    'insert' => ["INSERT INTO users (name) VALUES ('Eve')", 'Only read-only queries'],
    'update' => ["UPDATE users SET name = 'Eve'", 'Only read-only queries'],
    'delete' => ['DELETE FROM users', 'Only read-only queries'],
    'drop' => ['DROP TABLE users', 'Only read-only queries'],
    'create' => ['CREATE TABLE extra (id INTEGER)', 'Only read-only queries'],
    'alter' => ['ALTER TABLE users ADD COLUMN age INTEGER', 'Only read-only queries'],
    'pragma' => ['PRAGMA query_only = OFF', 'Only read-only queries'],
    'stacked' => ['SELECT 1; DELETE FROM users', 'single statement'],
    'stacked after literal' => ["SELECT ';'; DROP TABLE users", 'single statement'],
    'write cte' => ['WITH gone AS (DELETE FROM users RETURNING *) SELECT * FROM gone', 'data-modifying'],
    'cte without select' => ['WITH x AS (SELECT 1) DELETE FROM users', 'must end in a SELECT'],
    'explain analyze write' => ['EXPLAIN ANALYZE DELETE FROM users', 'EXPLAIN may only target'],
    'explain write' => ["EXPLAIN UPDATE users SET name = 'x'", 'EXPLAIN may only target'],
    'select into' => ['SELECT * INTO backup FROM users', 'INTO writes data'],
    'into outfile' => ["SELECT * FROM users INTO OUTFILE '/tmp/users'", 'INTO writes data'],
    'executable comment' => ['SELECT 1 /*! ; DROP TABLE users */', 'executable comments'],
    'subquery insert' => ["SELECT * FROM (INSERT INTO users (name) VALUES ('x') RETURNING *)", 'data-modifying'],
    'stacked after bracketed identifier' => ["SELECT 1 AS [x'] ; DELETE FROM users -- '", 'single statement'],
]);

it('reads comments and quoted text the way the connection engine does, rejecting before connecting', function (string $driver, string $sql, string $reason) {
    // Nothing listens on port 1, so reaching the database would fail with "Query failed".
    config()->set('database.connections.offline', ['driver' => $driver, 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => 'x']);

    $result = query($sql, 'offline');

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('Query rejected', $reason)
        ->and($result->output)->not->toContain('Query failed');
})->with([
    'mysql dashes without a space are subtraction' => ['mysql', "SELECT 1--1 INTO OUTFILE '/tmp/x'", 'INTO writes data'],
    'mysql hash comment' => ['mysql', "SELECT 1 # '\nINTO OUTFILE '/tmp/x'", 'INTO writes data'],
    'mysql backslash escape' => ['mysql', "SELECT '\\'' INTO OUTFILE '/tmp/x'", 'INTO writes data'],
    'mysql ANSI_QUOTES identifier' => ['mysql', "SELECT 1 AS \"\\\", 2 INTO OUTFILE '/tmp/x' -- \"", 'INTO writes data'],
    'mysql NO_BACKSLASH_ESCAPES string' => ['mysql', "SELECT 'a\\' INTO OUTFILE '/tmp/x' -- '", 'INTO writes data'],
    'mariadb dashes without a space are subtraction' => ['mariadb', "SELECT 1--1 INTO DUMPFILE '/tmp/x'", 'INTO writes data'],
    'mariadb executable comment' => ['mariadb', "SELECT 1 /*M! INTO OUTFILE '/tmp/x' */", 'executable comments'],
    'pgsql nested comment' => ['pgsql', "/* /* */ SELECT */ COPY (SELECT 1) TO '/tmp/x'", 'Only read-only queries'],
    'pgsql escape string' => ['pgsql', "SELECT E'\\'' ; COPY (SELECT 1) TO '/tmp/x' --'", 'single statement'],
    'pgsql dollar quoting' => ['pgsql', "SELECT \$\$'\$\$ ; COPY (SELECT 1) TO '/tmp/x' --'", 'single statement'],
    'pgsql without standard_conforming_strings' => ['pgsql', "SELECT 'a\\'' ; COPY (SELECT 1) TO '/tmp/x' --'", 'single statement'],
]);

it('rejects blank queries and requires the query argument', function () {
    expect(query('   ')->exitCode)->toBe(1)
        ->and(query('   ')->output)->toContain('Pass a SQL query');

    expect(fn () => runCommand('afterburner:database:query'))->toThrow(RuntimeException::class, 'Not enough arguments');
});

it('reports unknown connections and invalid SQL as failures', function (string $sql, ?string $connection, string $message) {
    $result = query($sql, $connection);

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain($message)
        ->and(json_decode($result->output))->toBeNull()
        ->and($result->output)->not->toContain('"password"');
})->with([
    'unknown connection' => ['SELECT 1', 'missing', 'Query failed: Database connection [missing] not configured.'],
    'invalid sql' => ['SELECT FROM WHERE', null, 'Query failed:'],
    'missing table' => ['SELECT * FROM nope', null, 'no such table: nope'],
]);

it('unwinds the transaction and query-only state after success and failure', function () {
    $connection = DB::connection('primary');

    query('SELECT * FROM users');
    query('SELECT * FROM missing_table');

    expect($connection->transactionLevel())->toBe(0)
        ->and($connection->selectOne('PRAGMA query_only')->query_only)->toBe(0);

    $connection->insert("INSERT INTO users (name) VALUES ('Margaret')");

    expect($connection->table('users')->count())->toBe(3);
});

it('restores a connection that was already query-only', function () {
    $connection = DB::connection('primary');
    $connection->statement('PRAGMA query_only = ON');

    expect(query('SELECT * FROM users')->successful())->toBeTrue()
        ->and($connection->selectOne('PRAGMA query_only')->query_only)->toBe(1);

    $connection->statement('PRAGMA query_only = OFF');
});

it('adds the connection table prefix without rewriting literals, comments, or prefixed names', function () {
    sqliteConnection($this->fixture, 'prefixed', ['Ada', 'Grace'], 'app_');
    DB::connection('prefixed')->update("UPDATE app_users SET note = 'FROM users' WHERE name = 'Ada'");

    $result = query("SELECT name, 'JOIN users' AS label FROM users -- FROM users\nWHERE note = 'FROM users'", 'prefixed');

    expect($result->successful())->toBeTrue($result->output)
        ->and(json_decode($result->output, true))->toBe([['name' => 'Ada', 'label' => 'JOIN users']]);

    expect(json_decode(query('SELECT count(*) AS total FROM app_users', 'prefixed')->output, true))->toBe([['total' => 2]])
        ->and(json_decode(query('WITH users AS (SELECT 7 AS n) SELECT n FROM users', 'prefixed')->output, true))->toBe([['n' => 7]]);
});

it('refuses drivers without enforced read-only execution', function () {
    config()->set('database.connections.unsupported', ['driver' => 'sqlsrv', 'database' => 'x', 'host' => 'localhost', 'username' => 'secret-user', 'password' => 'secret-password']);

    $result = query('SELECT 1', 'unsupported');

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('not supported for the [sqlsrv] driver')
        ->and($result->output)->not->toContain('secret-password');
});

it('runs under every preset and without configuration', function () {
    expect(query('SELECT 1 AS one')->successful())->toBeTrue();

    $this->fixture->configure(['agents' => [], 'base' => 'none']);

    expect(query('SELECT 1 AS one')->successful())->toBeTrue();
});
