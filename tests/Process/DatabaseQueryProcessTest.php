<?php

declare(strict_types=1);

use Tests\Support\HostProject;

beforeEach(function () {
    $this->host = HostProject::create();
    $this->database = $this->host->path('database/app.sqlite');

    is_dir(dirname($this->database)) || mkdir(dirname($this->database), 0755, true);
    $pdo = new PDO('sqlite:'.$this->database);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users (name) VALUES ('Ada'), ('Grace');");
});

afterEach(function () {
    $this->host->destroy();
});

it('prints only JSON on stdout for a successful query', function () {
    $process = $this->host->artisan(['afterburner:database:query', 'SELECT name FROM users ORDER BY id'], ['DB_DATABASE' => $this->database]);

    expect($process->getExitCode())->toBe(0)
        ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([['name' => 'Ada'], ['name' => 'Grace']])
        ->and($process->getErrorOutput())->toBe('');
});

it('reports failures on stderr with a nonzero exit code and no stdout result', function (array $arguments, string $message) {
    $process = $this->host->artisan(['afterburner:database:query', ...$arguments], ['DB_DATABASE' => $this->database]);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain($message);

    $pdo = new PDO('sqlite:'.$this->database);

    expect((int) $pdo->query('SELECT count(*) FROM users')->fetchColumn())->toBe(2);
})->with([
    'rejected write' => [['DELETE FROM users'], 'Query rejected'],
    'unknown connection' => [['SELECT 1', '--connection=missing'], 'Database connection [missing] not configured'],
    'invalid SQL' => [['SELECT * FROM missing'], 'no such table'],
]);
