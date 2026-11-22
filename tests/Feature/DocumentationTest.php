<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;

function repositoryPath(string $relative = ''): string
{
    return dirname(__DIR__, 2).($relative === '' ? '' : '/'.$relative);
}

/**
 * @return array<int, string>
 */
function codeBlocks(string $markdown, string $info): array
{
    preg_match_all('/^```'.preg_quote($info, '/').'\n(.*?)^```$/ms', $markdown, $matches);

    return $matches[1];
}

/**
 * @return array<int, string> relative link targets without anchors
 */
function relativeLinks(string $markdown): array
{
    $markdown = (string) preg_replace('/^```.*?^```$/ms', '', $markdown);
    preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/', $markdown, $matches);

    return array_values(array_filter(
        array_map(fn (string $target): string => explode('#', $target)[0], $matches[1]),
        fn (string $target): bool => $target !== '' && ! preg_match('#^[a-z]+:#i', $target),
    ));
}

it('documents afterburner.json examples that the install command accepts', function () {
    $examples = codeBlocks(file_get_contents(repositoryPath('README.md')), 'json afterburner.json');

    expect($examples)->not->toBeEmpty();

    foreach ($examples as $example) {
        $this->fixture->write('afterburner.json', $example);

        $result = runCommand('afterburner:install');

        expect($result->successful())->toBeTrue($result->output)
            ->and(Arr::except($this->fixture->configuration(), 'published'))->toEqual(json_decode($example, true));
    }
});

it('documents Composer hooks that call registered commands', function () {
    foreach (codeBlocks(file_get_contents(repositoryPath('README.md')), 'json composer.json') as $example) {
        $scripts = json_decode($example, true, flags: JSON_THROW_ON_ERROR)['scripts'];

        foreach (array_merge(...array_values($scripts)) as $script) {
            preg_match('/^@php artisan (\S+)(.*)$/', $script, $matches);

            expect(array_keys(Artisan::all()))->toContain($matches[1]);
        }
    }
});

it('only documents commands and options that exist', function (string $file) {
    $markdown = file_get_contents(repositoryPath($file));
    $commands = Artisan::all();

    preg_match_all('/php artisan ([a-z][a-z:-]*)([^`\n]*)/', $markdown, $matches, PREG_SET_ORDER);

    expect($matches)->not->toBeEmpty();

    foreach ($matches as [, $name, $rest]) {
        expect($commands)->toHaveKey($name);

        preg_match_all('/(?<=\s)--([a-z-]+)/', $rest, $options);

        foreach ($options[1] as $option) {
            expect($commands[$name]->getDefinition()->hasOption($option) || $commands[$name]->getApplication()?->getDefinition()->hasOption($option))
                ->toBeTrue("{$name} has no --{$option} option");
        }
    }
})->with(['README.md']);

it('only links to files that exist', function (string $file) {
    $directory = dirname(repositoryPath($file));
    $links = relativeLinks(file_get_contents(repositoryPath($file)));

    expect($links)->toBeArray();

    foreach ($links as $target) {
        expect(file_exists($directory.'/'.$target))->toBeTrue("{$file} links to missing {$target}");
    }
})->with(fn () => array_map(
    fn (string $path) => substr($path, strlen(repositoryPath()) + 1),
    [repositoryPath('README.md'), ...glob(repositoryPath('docs/*.md'))],
));

it('publishes skills whose relative references resolve', function () {
    $this->fixture
        ->composerPackage('laravel/framework', '12.3.0')
        ->composerPackage('livewire/livewire', '4.0.1')
        ->composerPackage('pestphp/pest', '4.0.0', dev: true)
        ->npmPackage('tailwindcss', '4.1.0');

    expect(runCommand('afterburner:install', ['--agents' => 'claude', '--base' => 'boost'])->successful())->toBeTrue();

    $checked = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->fixture->path('.claude/skills'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with($file->getFilename(), '.md')) {
            continue;
        }

        foreach (relativeLinks(file_get_contents($file->getPathname())) as $target) {
            expect(file_exists(dirname($file->getPathname()).'/'.$target))->toBeTrue(substr($file->getPathname(), strlen($this->fixture->path()) + 1)." links to missing {$target}");
            $checked++;
        }

        preg_match_all('/`((?:references?|rules)\/[\w.-]+\.md)`/', file_get_contents($file->getPathname()), $mentions);

        $relative = substr($file->getPathname(), strlen($this->fixture->path('.claude/skills')) + 1);
        $skillRoot = $this->fixture->path('.claude/skills/'.explode('/', $relative)[0]);

        foreach ($mentions[1] as $mention) {
            expect(file_exists($skillRoot.'/'.$mention))->toBeTrue("{$relative} mentions missing {$mention}");
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(5);
});
