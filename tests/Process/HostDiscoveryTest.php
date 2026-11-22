<?php

declare(strict_types=1);

use Tests\Support\HostProject;

beforeEach(function () {
    $this->host = HostProject::create();
});

afterEach(function () {
    $this->host->destroy();
});

it('is installed by Composer and auto-discovered by Laravel', function () {
    $installed = json_decode(file_get_contents($this->host->path('vendor/composer/installed.json')), true);
    $names = collect($installed['packages'])->pluck('name');

    expect($names)->toContain('shipwelldev/afterburner')
        ->not->toContain('laravel/boost')
        ->not->toContain('laravel/mcp');

    $manifest = require $this->host->path('bootstrap/cache/packages.php');

    expect($manifest['shipwelldev/afterburner']['providers'])
        ->toBe(['Shipwell\\Afterburner\\AfterburnerServiceProvider']);
});

it('lists and describes the commands in a real artisan process without side effects', function () {
    $before = $this->host->application()->snapshot();

    $list = $this->host->artisan(['list', '--format=json']);

    expect($list->isSuccessful())->toBeTrue($list->getErrorOutput());

    $commands = collect(json_decode($list->getOutput(), true)['commands'])->pluck('name');

    expect($commands)->toContain(
        'afterburner:install',
        'afterburner:update',
        'afterburner:index-rules',
        'afterburner:database:query',
        'boost:install',
        'boost:update',
        'boost:index-rules',
    )->not->toContain('boost:mcp');

    foreach (['afterburner:install', 'boost:update', 'afterburner:database:query'] as $command) {
        $help = $this->host->artisan(['help', $command]);

        expect($help->isSuccessful())->toBeTrue($help->getErrorOutput())
            ->and($help->getOutput())->toContain($command);
    }

    expect($this->host->application()->snapshot())->toBe($before);
});

it('leaves application routing and middleware unchanged', function () {
    $routes = fn (): array => json_decode($this->host->artisan(['route:list', '--json', '-v'])->getOutput(), true);

    $withAfterburner = $routes();

    $composer = $this->host->application()->json('composer.json');
    $composer['extra']['laravel']['dont-discover'] = ['shipwelldev/afterburner'];
    $this->host->application()->writeJson('composer.json', $composer);
    $this->host->artisan(['package:discover']);

    expect(require $this->host->path('bootstrap/cache/packages.php'))->not->toHaveKey('shipwelldev/afterburner')
        ->and($withAfterburner)->toBe($routes());
});
