<?php

declare(strict_types=1);

use Tests\Support\HostProject;

beforeEach(function () {
    $this->host = HostProject::create();
});

afterEach(function () {
    $this->host->destroy();
});

it('refreshes through an existing boost:update --ansi Composer hook', function () {
    $application = $this->host->application();

    $composer = $application->json('composer.json');
    $composer['scripts']['post-update-cmd'] = ['@php artisan boost:update --ansi'];
    $application->writeJson('composer.json', $composer);
    $application->configure(['agents' => ['claude', 'codex'], 'base' => 'boost']);
    $application->guideline('team.md', "Team guidance.\n");

    $hook = HostProject::composer(['run-script', 'post-update-cmd'], $this->host->path(), [
        'HOME' => $this->host->path('storage'),
        ...array_filter(HostProject::processEnvironment($this->host->path('storage')), fn ($value) => $value !== false),
    ]);

    expect($hook->getExitCode())->toBe(0, $hook->getOutput().$hook->getErrorOutput())
        ->and($hook->getOutput().$hook->getErrorOutput())->toContain("\e[")
        ->and($application->read('CLAUDE.md'))->toContain('=== .ai/team rules ===', '=== foundation rules ===')
        ->and($application->read('AGENTS.md'))->toContain('=== .ai/team rules ===')
        ->and(is_dir($this->host->path('.claude/skills/infer-conventions')))->toBeTrue();

    $snapshot = $application->snapshot();
    $again = $this->host->artisan(['afterburner:update']);

    expect($again->getExitCode())->toBe(0)
        ->and($application->snapshot())->toBe($snapshot);
});

it('fails the hook clearly when no agents were ever chosen', function () {
    $application = $this->host->application();

    $composer = $application->json('composer.json');
    $composer['scripts']['post-update-cmd'] = ['@php artisan boost:update --ansi'];
    $application->writeJson('composer.json', $composer);

    $hook = HostProject::composer(['run-script', 'post-update-cmd'], $this->host->path(), array_filter(HostProject::processEnvironment($this->host->path('storage')), fn ($value) => $value !== false));

    expect($hook->getExitCode())->not->toBe(0)
        ->and($hook->getOutput().$hook->getErrorOutput())->toContain('No agents are saved')
        ->and($application->exists('CLAUDE.md'))->toBeFalse()
        ->and($application->exists('AGENTS.md'))->toBeFalse();
});
