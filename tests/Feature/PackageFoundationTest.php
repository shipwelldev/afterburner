<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

const CANONICAL_COMMANDS = [
    'afterburner:install',
    'afterburner:update',
    'afterburner:index-rules',
    'afterburner:database:query',
];

const COMPATIBILITY_COMMANDS = [
    'boost:install' => 'afterburner:install',
    'boost:update' => 'afterburner:update',
    'boost:index-rules' => 'afterburner:index-rules',
];

function listedCommands(): array
{
    Artisan::call('list', ['--format' => 'json']);

    $listing = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    return collect($listing['commands'])->pluck('name')->all();
}

it('lists the canonical commands and compatibility entry points without Boost installed', function () {
    expect(class_exists('Laravel\\Boost\\BoostServiceProvider'))->toBeFalse();

    $commands = listedCommands();

    expect($commands)
        ->toContain(...CANONICAL_COMMANDS)
        ->toContain(...array_keys(COMPATIBILITY_COMMANDS))
        ->not->toContain('boost:mcp')
        ->not->toContain('boost:execute-tool')
        ->not->toContain('boost:start');
});

it('shows help for every public command', function (string $command) {
    expect(Artisan::call('help', ['command_name' => $command]))->toBe(0)
        ->and(Artisan::output())->toContain($command);
})->with([...CANONICAL_COMMANDS, ...array_keys(COMPATIBILITY_COMMANDS)]);

it('describes compatibility entry points as the corresponding Afterburner operation', function (string $alias, string $canonical) {
    Artisan::call('help', ['command_name' => $alias]);

    expect(Artisan::output())->toContain($canonical);
})->with(array_map(
    fn (string $alias, string $canonical): array => [$alias, $canonical],
    array_keys(COMPATIBILITY_COMMANDS),
    COMPATIBILITY_COMMANDS,
));

it('accepts the documented query command arguments', function () {
    Artisan::call('help', ['command_name' => 'afterburner:database:query']);

    expect(Artisan::output())
        ->toContain('query')
        ->toContain('--connection');
});

it('boots without writing files, adding routes, or adding middleware', function () {
    $before = $this->fixture->snapshot();

    $this->rebootApplication();
    listedCommands();
    Artisan::call('help', ['command_name' => 'afterburner:install']);

    expect($this->fixture->snapshot())->toBe($before)
        ->and($this->fixture->exists('afterburner.json'))->toBeFalse();

    $routeActions = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getActionName().' '.implode(',', $route->gatherMiddleware()));

    expect($routeActions->filter(fn (string $action) => str_contains($action, 'Afterburner')))->toBeEmpty();

    $middleware = $this->app->make(HttpKernel::class)->getMiddlewareGroups();
    $global = $this->app->make(HttpKernel::class)->getGlobalMiddleware();

    expect(collect($middleware)->flatten()->merge($global)->filter(
        fn ($class) => is_string($class) && str_contains($class, 'Afterburner'),
    ))->toBeEmpty();
});

it('declares no Boost, MCP, or Tinker runtime dependency', function () {
    $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['name'])->toBe('shipwelldev/afterburner')
        ->and($composer['extra']['laravel']['providers'])->toBe(['Shipwell\\Afterburner\\AfterburnerServiceProvider'])
        ->and(array_keys($composer['require']))
        ->not->toContain('laravel/boost')
        ->not->toContain('laravel/mcp')
        ->not->toContain('laravel/tinker');
});
