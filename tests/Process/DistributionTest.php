<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\FixtureApplication;
use Tests\Support\HostProject;
use Tests\Support\TemporaryDirectory;

/**
 * Builds the Composer distribution archive and installs it into a fresh host
 * the way a consumer receives it: copied files, not a symlink to this checkout.
 */
beforeEach(function () {
    $this->workspace = TemporaryDirectory::make('afterburner-dist-');

    $archive = HostProject::composer(['archive', '--format=tar', '--dir='.$this->workspace, '--file=afterburner'], dirname(__DIR__, 2));

    expect($archive->isSuccessful())->toBeTrue($archive->getErrorOutput());

    $this->archive = new PharData($this->workspace.'/afterburner.tar');
    $this->files = [];

    foreach (new RecursiveIteratorIterator($this->archive) as $file) {
        $this->files[] = substr($file->getPathname(), strlen('phar://'.$this->workspace.'/afterburner.tar/'));
    }
});

afterEach(function () {
    unset($this->archive);
    TemporaryDirectory::remove($this->workspace);
});

it('contains the package, its attributed assets, and nothing for development', function () {
    $files = $this->files;
    $provenance = json_decode(file_get_contents('phar://'.$this->workspace.'/afterburner.tar/resources/boost/PROVENANCE.json'), true);

    expect($files)->toContain(
        'composer.json',
        'LICENSE.md',
        'README.md',
        'src/AfterburnerServiceProvider.php',
        'resources/boost/LICENSE.md',
        'resources/boost/PROVENANCE.json',
        'resources/afterburner/LICENSE.md',
        'resources/core/skills/afterburner-boost-tool-compatibility/SKILL.blade.php',
        'resources/core/skills/afterburner-project-rules/SKILL.blade.php',
    );

    expect(array_values(array_filter($files, fn (string $file) => str_starts_with($file, 'resources/core/'))))->toHaveCount(5);

    foreach (array_keys($provenance['files']) as $asset) {
        expect($files)->toContain('resources/boost/'.$asset);
    }

    foreach ($files as $file) {
        expect($file)->not->toStartWith('tests/')->not->toStartWith('docs/')->not->toStartWith('vendor/')->not->toStartWith('bin/')->not->toStartWith('.github/');
    }
});

it('installs from the distribution and publishes without fetching anything', function () {
    $package = $this->workspace.'/package';
    $this->archive->extractTo($package);

    $host = FixtureApplication::at($this->workspace.'/host');
    @mkdir($host->path(), 0755, true);

    $host->writeJson('composer.json', [
        'name' => 'fixture/distribution-host',
        'type' => 'project',
        'require' => ['php' => '^8.4', 'laravel/framework' => '^13.0', 'shipwelldev/afterburner' => '1.0.0'],
        'repositories' => [['type' => 'path', 'url' => $package, 'options' => ['symlink' => false, 'versions' => ['shipwelldev/afterburner' => '1.0.0']]]],
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
        'scripts' => ['post-autoload-dump' => ['Illuminate\\Foundation\\ComposerScripts::postAutoloadDump', '@php artisan package:discover --ansi']],
        'minimum-stability' => 'stable',
    ]);

    (new Process(['cp', '-R', HostProject::template().'/artisan', HostProject::template().'/bootstrap', HostProject::template().'/routes', HostProject::template().'/app', HostProject::template().'/storage', $host->path()]))->mustRun();

    $install = HostProject::composer(['install', '--no-interaction', '--no-progress', '--prefer-dist'], $host->path());

    expect($install->isSuccessful())->toBeTrue($install->getErrorOutput())
        ->and(is_link($host->path('vendor/shipwelldev/afterburner')))->toBeFalse()
        ->and($host->exists('vendor/shipwelldev/afterburner/resources/boost/PROVENANCE.json'))->toBeTrue();

    $installed = collect(json_decode($host->read('vendor/composer/installed.json'), true)['packages'])->pluck('name');

    expect($installed)->not->toContain('laravel/boost')->not->toContain('laravel/mcp')->not->toContain('laravel/tinker');

    $unreachable = 'http://127.0.0.1:9';
    $artisan = new Process(
        [PHP_BINARY, 'artisan', 'afterburner:install', '--agents=claude,codex', '--base=boost', '--no-interaction'],
        $host->path(),
        [...HostProject::processEnvironment($this->workspace), 'HTTP_PROXY' => $unreachable, 'HTTPS_PROXY' => $unreachable, 'ALL_PROXY' => $unreachable],
    );
    $artisan->run();

    expect($artisan->getExitCode())->toBe(0, $artisan->getOutput().$artisan->getErrorOutput())
        ->and($host->read('CLAUDE.md'))->toContain('=== foundation rules ===', '=== laravel/core rules ===')
        ->and($host->exists('.agents/skills/laravel-best-practices/SKILL.md'))->toBeTrue()
        ->and($host->exists('.claude/skills/afterburner-boost-tool-compatibility/SKILL.md'))->toBeTrue();
});
