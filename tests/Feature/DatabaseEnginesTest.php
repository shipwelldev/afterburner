<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommandResult;

/**
 * Real database engine checks for the read-only query command.
 *
 * Each engine runs only when its connection is provided through environment
 * variables (for example AFTERBURNER_TEST_MYSQL_HOST, _PORT, _DATABASE,
 * _USERNAME, _PASSWORD). Without them the engine is reported as skipped,
 * never as passing.
 */
function engineConfiguration(string $engine, string $prefix = ''): array
{
    $key = 'AFTERBURNER_TEST_'.strtoupper($engine).'_';
    $host = getenv($key.'HOST');

    if (! is_string($host) || $host === '') {
        test()->markTestSkipped("{$engine} is not available: set {$key}HOST, {$key}PORT, {$key}DATABASE, {$key}USERNAME, and {$key}PASSWORD to run these checks.");
    }

    return [
        'driver' => $engine,
        'host' => $host,
        'port' => getenv($key.'PORT') ?: ($engine === 'pgsql' ? '5432' : '3306'),
        'database' => getenv($key.'DATABASE') ?: 'afterburner',
        'username' => getenv($key.'USERNAME') ?: 'root',
        'password' => getenv($key.'PASSWORD') ?: '',
        'charset' => $engine === 'pgsql' ? 'utf8' : 'utf8mb4',
        'prefix' => $prefix,
        'search_path' => 'public',
    ];
}

function prepareEngine(string $engine, string $prefix = ''): Connection
{
    config()->set('database.connections.engine', engineConfiguration($engine, $prefix));
    config()->set('database.default', 'engine');

    $connection = DB::connection('engine');
    $table = $prefix.'users';

    $connection->statement('DROP FUNCTION IF EXISTS afterburner_write'.($engine === 'pgsql' ? '()' : ''));
    $connection->statement("DROP TABLE IF EXISTS {$table}");

    if ($engine === 'pgsql') {
        $connection->statement("CREATE TABLE {$table} (id SERIAL PRIMARY KEY, name VARCHAR(50) NOT NULL, note VARCHAR(50))");
        $connection->statement("CREATE FUNCTION afterburner_write() RETURNS integer AS \$\$ INSERT INTO {$table} (name) VALUES ('Intruder') RETURNING 1 \$\$ LANGUAGE sql");
    } else {
        $connection->statement("CREATE TABLE {$table} (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL, note VARCHAR(50))");
        $connection->unprepared("CREATE FUNCTION afterburner_write() RETURNS INT MODIFIES SQL DATA BEGIN INSERT INTO {$table} (name) VALUES ('Intruder'); RETURN 1; END");
    }

    $connection->insert("INSERT INTO {$table} (name, note) VALUES ('Ada', 'FROM users'), ('Grace', NULL)");

    return $connection;
}

function engineQuery(string $sql): CommandResult
{
    return runCommand('afterburner:database:query', ['query' => $sql, '--connection' => 'engine']);
}

it('returns rows and empty results as JSON', function (string $engine) {
    prepareEngine($engine);

    $rows = engineQuery('SELECT name FROM users ORDER BY id');
    $empty = engineQuery("SELECT name FROM users WHERE name = 'nobody'");

    expect($rows->successful())->toBeTrue($rows->output)
        ->and(json_decode($rows->output, true))->toBe([['name' => 'Ada'], ['name' => 'Grace']])
        ->and(trim($empty->output))->toBe('[]');
})->with(['mysql', 'mariadb', 'pgsql']);

it('rejects writes before execution', function (string $engine) {
    $connection = prepareEngine($engine);

    foreach (['DELETE FROM users', "UPDATE users SET name = 'x'", 'SELECT 1; DROP TABLE users', 'SELECT 1 /*! ; DELETE FROM users */'] as $sql) {
        expect(engineQuery($sql)->exitCode)->toBe(1);
    }

    expect($connection->table('users')->count())->toBe(2);
})->with(['mysql', 'mariadb', 'pgsql']);

it('relies on the engine to block writes the validator cannot see', function (string $engine) {
    $connection = prepareEngine($engine);

    $result = engineQuery('SELECT afterburner_write() AS written');

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('Query failed:')
        ->and(strtolower($result->output))->toContain('read')
        ->and($connection->table('users')->count())->toBe(2);
})->with(['mysql', 'mariadb', 'pgsql']);

it('leaves the connection usable for writes after success and failure', function (string $engine) {
    $connection = prepareEngine($engine);

    engineQuery('SELECT name FROM users');
    engineQuery('SELECT * FROM missing_table');
    engineQuery('SELECT afterburner_write()');

    expect($connection->transactionLevel())->toBe(0);

    $connection->transaction(fn () => $connection->insert("INSERT INTO users (name) VALUES ('Margaret')"));
    $connection->insert("INSERT INTO users (name) VALUES ('Katherine')");

    expect($connection->table('users')->count())->toBe(4);
})->with(['mysql', 'mariadb', 'pgsql']);

it('prefixes tables without rewriting literals or comments', function (string $engine) {
    prepareEngine($engine, 'app_');

    $escaped = $engine === 'pgsql' ? "'it''s FROM users'" : "'it\\'s FROM users'";
    $result = engineQuery("SELECT name, {$escaped} AS label FROM users -- FROM users\nWHERE note = 'FROM users'");

    expect($result->successful())->toBeTrue($result->output)
        ->and(json_decode($result->output, true))->toBe([['name' => 'Ada', 'label' => "it's FROM users"]])
        ->and(json_decode(engineQuery('SELECT count(*) AS total FROM app_users')->output, true))->toEqual([['total' => 2]]);
})->with(['mysql', 'mariadb', 'pgsql']);

it('accepts engine syntax the validator must read the way the engine does', function (string $engine, string $sql, array $rows) {
    prepareEngine($engine);

    $result = engineQuery($sql);

    expect($result->successful())->toBeTrue($result->output)
        ->and(json_decode($result->output, true))->toEqual($rows);
})->with([
    'mysql subtraction' => ['mysql', 'SELECT 1--1 AS two', [['two' => 2]]],
    'mysql backslash escape and hash comment' => ['mysql', "SELECT 'it\\'s' AS label # trailing comment", [['label' => "it's"]]],
    'mariadb subtraction' => ['mariadb', 'SELECT 1--1 AS two', [['two' => 2]]],
    'mariadb backslash escape and hash comment' => ['mariadb', "SELECT 'it\\'s' AS label # trailing comment", [['label' => "it's"]]],
    'pgsql dollar quoting, escape strings, and nested comments' => ['pgsql', "SELECT \$\$it's\$\$ AS a, E'it\\'s' AS b /* outer /* inner */ still a comment */", [['a' => "it's", 'b' => "it's"]]],
    'pgsql hash operator' => ['pgsql', "WITH x AS (SELECT '{\"a\":{\"b\":1}}'::jsonb #>> '{a,b}' AS v) SELECT v FROM x", [['v' => '1']]],
]);
