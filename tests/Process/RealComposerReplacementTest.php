<?php

declare(strict_types=1);

use Tests\Support\HostProject;

/**
 * Smoke tests with the real laravel/boost v2.10.4 package and a real `composer remove`.
 * Boost publishes its own output first, so the replacement adopts exactly what Boost writes.
 */
beforeEach(function () {
    $this->host = HostProject::createWithRealBoost();
    $this->application = $this->host->application();
    $this->environment = [...HostProject::composerEnvironment(), 'HOME' => $this->host->path('storage'), 'APP_ENV' => 'local'];

    $this->application
        ->writeJson('boost.json', ['agents' => ['claude_code', 'cursor'], 'guidelines' => true, 'mcp' => true])
        ->skill('team', 'The team skill.')
        ->write('AGENTS.md', "# Team notes\n");

    $boost = $this->host->artisan(['boost:install', '--guidelines', '--skills', '--mcp', '--no-interaction'], $this->environment);

    expect($boost->getExitCode())->toBe(0, $boost->getOutput().$boost->getErrorOutput())
        ->and($this->application->read('AGENTS.md'))->toContain('<laravel-boost-guidelines>');
});

afterEach(function () {
    $this->host->destroy();
});

it('replaces a real Boost installation through Composer', function () {
    $list = $this->host->artisan(['list', '--format=json'], $this->environment);
    $boostInstall = collect(json_decode($list->getOutput(), true)['commands'])->firstWhere('name', 'boost:install');

    expect($boostInstall['description'] ?? '')->not->toContain('afterburner');

    $process = $this->host->artisan(['afterburner:install', '--replace-boost', '--no-interaction'], $this->environment);
    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $output)
        ->and($output)->toContain('Laravel Boost has been replaced by Afterburner.')
        ->and($this->application->json('composer.json')['require-dev'] ?? [])->not->toHaveKey('laravel/boost')
        ->and(collect($this->application->json('composer.lock')['packages-dev'])->pluck('name'))->not->toContain('laravel/boost')
        ->and(is_dir($this->host->path('vendor/laravel/boost')))->toBeFalse()
        ->and($this->application->read('AGENTS.md'))->toStartWith("# Team notes\n\n<afterburner-guidelines>")->not->toContain('laravel-boost-guidelines')
        ->and($this->application->exists('.mcp.json'))->toBeFalse()
        ->and($this->application->exists('.cursor/mcp.json'))->toBeFalse()
        ->and(is_link($this->host->path('.claude/skills/team')))->toBeFalse()
        ->and($this->application->exists('.claude/skills/laravel-best-practices/SKILL.md'))->toBeTrue()
        ->and($this->application->configuration())->toMatchArray(['agents' => ['claude', 'cursor'], 'base' => 'boost']);

    $update = $this->host->artisan(['boost:update', '--ansi'], $this->environment);
    $snapshot = $this->application->snapshot();
    $again = $this->host->artisan(['boost:update'], $this->environment);

    expect($update->getExitCode())->toBe(0, $update->getOutput())
        ->and($again->getExitCode())->toBe(0)
        ->and($this->application->snapshot())->toBe($snapshot);
});

it('stops when another package still requires Boost', function () {
    $this->application->writeJson('packages/needs-boost/composer.json', ['name' => 'acme/needs-boost', 'version' => '1.0.0', 'require' => ['laravel/boost' => '^2.10']]);
    $composer = $this->application->json('composer.json');
    $composer['repositories'][] = ['type' => 'path', 'url' => 'packages/needs-boost'];
    $this->application->writeJson('composer.json', $composer);

    expect($this->host->runComposer(['require', 'acme/needs-boost:1.0.0', '--dev', '--no-interaction', '--no-progress'])->isSuccessful())->toBeTrue();

    $agents = $this->application->read('AGENTS.md');

    $process = $this->host->artisan(['afterburner:install', '--replace-boost', '--no-interaction'], $this->environment);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('Removal failed, laravel/boost is still present', 'it is still installed')
        ->and(is_dir($this->host->path('vendor/laravel/boost')))->toBeTrue()
        ->and($this->application->read('AGENTS.md'))->toBe($agents)
        ->and($this->application->read('.mcp.json'))->toContain('laravel-boost')
        ->and($this->application->exists('afterburner.json'))->toBeFalse()
        ->and($this->application->exists('storage/framework/afterburner-replacement.json'))->toBeFalse();
});
