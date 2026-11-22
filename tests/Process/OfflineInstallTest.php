<?php

declare(strict_types=1);

use Tests\Support\HostProject;

beforeEach(function () {
    $this->host = HostProject::create();
});

afterEach(function () {
    $this->host->destroy();
});

it('installs every preset from shipped files with outbound HTTP routed to a closed port', function (string $base) {
    $unreachable = 'http://127.0.0.1:9';

    $process = $this->host->artisan(['afterburner:install', '--agents=claude,codex', "--base={$base}", '--no-interaction'], [
        'HTTP_PROXY' => $unreachable,
        'HTTPS_PROXY' => $unreachable,
        'ALL_PROXY' => $unreachable,
        'http_proxy' => $unreachable,
        'https_proxy' => $unreachable,
        'NO_PROXY' => '',
    ]);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());

    if ($base === 'boost') {
        expect($this->host->application()->read('CLAUDE.md'))->toContain('=== foundation rules ===')
            ->and(is_dir($this->host->path('.agents/skills/infer-conventions')))->toBeTrue();
    }
})->with(['boost', 'afterburner']);
