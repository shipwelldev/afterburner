<?php

declare(strict_types=1);

use Tests\Support\BoostApplication;
use Tests\Support\HostProject;

beforeEach(function () {
    $this->host = HostProject::create(isolatedVendor: true)->installFakeBoost();
    $this->application = $this->host->application();
    $this->home = $this->host->path('storage/home');
    @mkdir($this->home, 0755, true);

    BoostApplication::seed($this->application, $this->home);

    $composer = $this->application->json('composer.json');
    $composer['scripts']['post-update-cmd'] = ['@php artisan boost:update --ansi'];
    $this->application->writeJson('composer.json', $composer);

    $this->log = $this->host->path('storage/composer.log');
    $this->environment = [
        'HOME' => $this->home,
        'AFTERBURNER_COMPOSER' => dirname(__DIR__).'/Fixtures/bin/fake-composer.php',
        'FAKE_COMPOSER_LOG' => $this->log,
        'FAKE_COMPOSER_MODE' => 'remove',
    ];
});

afterEach(function () {
    $this->host->destroy();
});

it('removes Boost, runs Composer callbacks safely, and finishes in a fresh process', function () {
    $process = $this->host->artisan(['afterburner:install', '--replace-boost', '--no-interaction'], $this->environment);
    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $output)
        ->and($output)
        ->toContain('Removing laravel/boost with Composer')
        ->toContain('> @php artisan boost:update --ansi')
        ->toContain('replacement is in progress; this refresh used the choices it recorded')
        ->toContain('Finishing the replacement in a fresh application process')
        ->toContain('Laravel Boost has been replaced by Afterburner.')
        ->and(json_decode(trim(file_get_contents($this->log)), true))->toBe(['remove', 'laravel/boost', '--no-interaction', '--no-progress', '--dev'])
        ->and($this->application->json('composer.json')['require-dev'] ?? [])->not->toHaveKey('laravel/boost')
        ->and(is_dir($this->host->path('vendor/laravel/boost')))->toBeFalse()
        ->and($this->application->exists('storage/framework/afterburner-replacement.json'))->toBeFalse()
        ->and($this->application->configuration())->toMatchArray(['agents' => ['claude', 'cursor'], 'base' => 'boost'])
        ->and($this->application->read('CLAUDE.md'))->toStartWith(BoostApplication::MANUAL_CLAUDE."\n<afterburner-guidelines>")->not->toContain('laravel-boost-guidelines')
        ->and($this->application->read('.mcp.json'))->not->toContain('laravel-boost')->toContain('github')
        ->and($this->application->read('.ai/rules/team.md'))->toBe(BoostApplication::USER_RULE)
        ->and($this->application->exists('.claude/skills/hand-made/SKILL.md'))->toBeTrue()
        ->and(file_get_contents($this->home.'/.cursor/mcp.json'))->toBe(BoostApplication::GLOBAL_CURSOR);
});

it('keeps the compatibility entry points working after Boost is removed', function () {
    $this->host->artisan(['afterburner:install', '--replace-boost', '--no-interaction'], $this->environment);
    $snapshot = $this->application->snapshot();

    foreach ([['boost:update', '--ansi'], ['boost:install', '--no-interaction'], ['afterburner:update']] as $command) {
        $process = $this->host->artisan($command, ['HOME' => $this->home]);

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
    }

    $index = $this->host->artisan(['boost:index-rules']);

    expect($index->getExitCode())->toBe(0)
        ->and($index->getOutput())->toContain('Regenerated .ai/rules/index.md')
        ->and($this->application->snapshot())->toBe($snapshot);
});

it('does nothing beyond Composer when removal is blocked by another package', function () {
    $before = $this->application->snapshot();

    $process = $this->host->artisan(['afterburner:install', '--replace-boost', '--no-interaction'], [...$this->environment, 'FAKE_COMPOSER_MODE' => 'blocked']);

    $after = $this->application->snapshot();
    unset($before['composer.json'], $after['composer.json']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('it is still installed')
        ->and($process->getErrorOutput())->toContain('Removal failed, laravel/boost is still present')
        ->and($after)->toBe($before);
});
