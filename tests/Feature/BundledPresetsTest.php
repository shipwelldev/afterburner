<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Support\BlockingStreamWrapper;
use Tests\Support\TemporaryDirectory;

const CLOUD_PROMOTION = 'which is the fastest way to deploy and scale production Laravel applications';

function resourcesPath(string $relative = ''): string
{
    return dirname(__DIR__, 2).'/resources'.($relative === '' ? '' : '/'.$relative);
}

/**
 * @return array<string, string> identity => body
 */
function presetSections(): array
{
    if (! test()->fixture->exists('CLAUDE.md')) {
        return [];
    }

    preg_match('/<afterburner-guidelines>\n(.*?)\n\n<\/afterburner-guidelines>/s', test()->fixture->read('CLAUDE.md'), $block);
    preg_match_all('/^=== (.+?) rules ===\n\n(.*?)(?=\n\n=== |\z)/ms', $block[1] ?? '', $matches, PREG_SET_ORDER);

    return array_combine(array_column($matches, 1), array_map('trim', array_column($matches, 2)));
}

/**
 * @return array<int, string>
 */
function publishedSkills(): array
{
    return array_map('basename', glob(test()->fixture->path('.claude/skills/*'), GLOB_ONLYDIR) ?: []);
}

function installPreset(string $base, array $configuration = []): void
{
    if ($configuration !== []) {
        test()->fixture->reconfigure($configuration);
    }

    $result = runCommand('afterburner:install', ['--agents' => 'claude', '--base' => $base]);

    if (! $result->successful()) {
        throw new RuntimeException($result->output);
    }
}

/**
 * @return array<string, string> relative path => sha256
 */
function treeHashes(string $root): array
{
    $hashes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $hashes[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
    }

    ksort($hashes, SORT_STRING);

    return $hashes;
}

describe('preset composition', function () {
    it('composes each preset for an empty application', function (string $base, array $guidelines, bool $promotion, array $skills) {
        installPreset($base);

        $sections = presetSections();

        expect(array_keys($sections))->toBe($guidelines)
            ->and(str_contains(test()->fixture->exists('CLAUDE.md') ? test()->fixture->read('CLAUDE.md') : '', CLOUD_PROMOTION))->toBe($promotion)
            ->and(publishedSkills())->toBe($skills);
    })->with([
        'boost' => ['boost', ['foundation', 'boost', 'php', 'deployments'], true, [...CORE_SKILLS, 'infer-conventions']],
        'core' => ['core', [], false, CORE_SKILLS],
        'none' => ['none', [], false, []],
    ]);

    it('publishes each ecosystem catalog\'s own core guideline and skills', function (string $base, string $folder) {
        installPreset($base);

        $catalog = resourcesPath("{$base}/{$folder}");

        if (is_file("{$catalog}/core.blade.php") || is_file("{$catalog}/core.md")) {
            expect(presetSections())->toHaveKey($folder);
        }

        foreach (glob("{$catalog}/skill/*/SKILL*") ?: [] as $entry) {
            preg_match('/^name:\s*"?([\w-]+)"?\s*$/m', file_get_contents($entry), $name);

            expect(publishedSkills())->toContain($name[1]);
        }
    })->with([
        'boost' => ['boost', 'boost'],
        'afterburner' => ['afterburner', 'afterburner'],
    ]);

    it('renders the Boost foundation without advertising an MCP server', function () {
        installPreset('boost', ['purpose' => 'Fleet maintenance tracking for regional depots.']);
        config()->set('boost.purpose', 'Ignored Boost purpose.');
        installPreset('boost');

        $sections = presetSections();

        expect($sections['foundation'])->toContain('Application purpose: Fleet maintenance tracking for regional depots.')->not->toContain('Ignored Boost purpose')
            ->and($sections['boost'])->toContain('## Searching Documentation', 'the `afterburner-documentation` skill', '## Project Rules', "tinker --execute 'Your::code();'")->not->toContain('## Tools');

        expect($sections['foundation'])->toContain('# Laravel Boost Guidelines')->toContain('composer show --direct')
            ->and($sections['boost'])->not->toContain('MCP server')->not->toContain('`search-docs`')->toContain('php artisan route:list')
            ->and($sections['php'])->toContain('constructor property promotion');
    });

    it('keeps application and dependency content in every preset', function (string $base) {
        $this->fixture
            ->guideline('team.md', "Team guidance.\n")
            ->skill('team-skill')
            ->composerPackage('acme/kit', '1.0.0', [
                'resources/boost/guidelines/core.md' => "Kit guidance.\n",
                'resources/boost/skills/kit-skill/SKILL.md' => "---\nname: kit-skill\ndescription: Kit.\n---\n",
            ]);

        installPreset($base);

        expect(presetSections())->toHaveKey('.ai/team')->toHaveKey('acme/kit/core')
            ->and(publishedSkills())->toContain('team-skill', 'kit-skill');
    })->with(['boost', 'afterburner', 'core', 'none']);
});

describe('applicability', function () {
    it('selects guidance for the installed Laravel major version', function (string $version, array $expected, array $absent) {
        $this->fixture->composerPackage('laravel/framework', $version);

        installPreset('boost');

        expect(presetSections())->toHaveKeys($expected);

        foreach ($absent as $key) {
            expect(presetSections())->not->toHaveKey($key);
        }
    })->with([
        'Laravel 11' => ['11.9.0', ['laravel/core', 'laravel/v11'], ['laravel/v12']],
        'Laravel 12' => ['12.3.0', ['laravel/core', 'laravel/v12'], ['laravel/v11']],
        'Laravel 13 has no versioned guidance' => ['13.4.0', ['laravel/core'], ['laravel/v11', 'laravel/v12', 'laravel/v13']],
    ]);

    it('selects versioned package skills and their supporting files', function () {
        $this->fixture
            ->composerPackage('livewire/livewire', '4.0.1')
            ->npmPackage('tailwindcss', '4.1.0', dev: true)
            ->npmPackage('@inertiajs/react', '1.3.0');

        installPreset('boost');

        expect(publishedSkills())->toContain('livewire-development', 'tailwindcss-development', 'inertia-react-development')
            ->and($this->fixture->exists('.claude/skills/livewire-development/reference/javascript-hooks.md'))->toBeTrue()
            ->and($this->fixture->read('.claude/skills/tailwindcss-development/SKILL.md'))->toContain('Tailwind CSS v4')
            ->and($this->fixture->read('.claude/skills/inertia-react-development/SKILL.md'))->toContain('## Inertia v1 limitations')
            ->and(presetSections())->toHaveKeys(['livewire/core', 'inertia-react/core']);
    });

    it('does not include transitive or suppressed package guidance', function () {
        $this->fixture
            ->composerPackage('livewire/livewire', '3.6.0', direct: false)
            ->composerPackage('pestphp/pest', '4.0.0', dev: true)
            ->composerPackage('phpunit/phpunit', '12.0.0', direct: false, dev: true);

        installPreset('boost');

        expect(presetSections())->toHaveKey('pest/core')->not->toHaveKey('phpunit/core')->not->toHaveKey('livewire/core')
            ->and(publishedSkills())->not->toContain('livewire-development');
    });

    it('applies the Cloud integration', function (string $base, bool $promotion) {
        installPreset($base, ['integrations' => ['cloud' => true]]);

        expect(publishedSkills())->toContain('deploying-to-cloud')
            ->and($this->fixture->exists('.claude/skills/deploying-to-cloud/reference/checklists.md'))->toBeTrue()
            ->and(presetSections()['deployments'])->toContain('Activate the `deploying-to-cloud` skill')
            ->and(str_contains(presetSections()['deployments'], CLOUD_PROMOTION))->toBe($promotion);
    })->with([
        'boost' => ['boost', true],
    ]);

    it('applies the Sail and test-enforcement guidance only when they apply', function () {
        installPreset('boost', ['integrations' => ['sail' => true], 'enforce_tests' => true]);

        expect(presetSections())->toHaveKeys(['sail', 'tests'])->not->toHaveKey('herd')
            ->and(presetSections()['foundation'])->toContain('vendor/bin/sail composer show --direct');

        installPreset('boost', ['enforce_tests' => false]);

        expect(presetSections())->not->toHaveKey('sail')->not->toHaveKey('tests');
    });

    it('detects a test suite for the test-enforcement guidance', function (int $tests, bool $enforced) {
        $this->fixture->write('vendor/bin/pest', '#!/usr/bin/env php');
        $this->fixture->write('tests/Feature/ExampleTest.php', "<?php\n\n".str_repeat("test('works', fn () => true);\n", $tests));

        installPreset('boost');

        expect(array_key_exists('tests', presetSections()))->toBe($enforced);
    })->with([
        'six tests' => [6, true],
        'five tests' => [5, false],
    ]);
});

describe('overrides and switches', function () {
    it('lets application sources replace bundled guidelines and skills', function () {
        $this->fixture
            ->composerPackage('laravel/framework', '12.3.0')
            ->guideline('foundation.md', "Our own foundation.\n")
            ->guideline('laravel/12/core.md', "Our Laravel 12 notes.\n")
            ->skill('laravel-best-practices', 'Our best practices.');

        installPreset('boost');

        expect(presetSections()['foundation'])->toBe('Our own foundation.')
            ->and(presetSections()['laravel/v12'])->toBe('Our Laravel 12 notes.')
            ->and(presetSections()['laravel/core'])->toContain('Laravel')
            ->and($this->fixture->read('.claude/skills/laravel-best-practices/SKILL.md'))->toContain('Our best practices.');
    });

    it('filters bundled package guidance with package switches', function () {
        $this->fixture->composerPackage('laravel/framework', '12.3.0');

        installPreset('boost', ['packages' => ['laravel/framework' => ['guidelines' => false]]]);

        expect(presetSections())->not->toHaveKey('laravel/core')->not->toHaveKey('laravel/v12')->toHaveKey('foundation')
            ->and(publishedSkills())->toContain('laravel-best-practices');

        installPreset('boost', ['packages' => ['laravel/framework' => ['skills' => false]]]);

        expect(presetSections())->toHaveKey('laravel/core')
            ->and(publishedSkills())->not->toContain('laravel-best-practices')->not->toContain('testing-best-practices');
    });

    it('removes stale bundled output when the preset changes', function () {
        $this->fixture->composerPackage('laravel/framework', '12.3.0')->skill('team-skill')->write('CLAUDE.md', "Manual.\n");

        installPreset('boost');

        expect(publishedSkills())->toContain('laravel-best-practices')
            ->and($this->fixture->read('CLAUDE.md'))->toContain(CLOUD_PROMOTION);

        installPreset('core');

        expect(publishedSkills())->toBe([...CORE_SKILLS, 'team-skill'])
            ->and($this->fixture->read('CLAUDE.md'))->toBe("Manual.\n");

        installPreset('none');

        expect(publishedSkills())->toBe(['team-skill'])
            ->and($this->fixture->read('CLAUDE.md'))->toBe("Manual.\n");
    });
});

describe('bundled snapshot', function () {
    it('renders every bundled template across representative applications', function (array $composer, array $npm) {
        foreach ($composer as $package => $version) {
            $this->fixture->composerPackage($package, $version);
        }

        foreach ($npm as $package => $version) {
            $this->fixture->npmPackage($package, $version);
        }

        installPreset('boost', ['integrations' => ['cloud' => true, 'sail' => true], 'enforce_tests' => true]);

        $output = $this->fixture->read('CLAUDE.md');

        $ecosystem = '';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->fixture->path('.claude/skills'), FilesystemIterator::SKIP_DOTS)) as $file) {
            $contents = file_get_contents($file->getPathname());
            $output .= $contents;

            // The compatibility skill names Boost's tools on purpose; everything else must not.
            if (! str_contains($file->getPathname(), '/.claude/skills/afterburner-')) {
                $ecosystem .= $contents;
            }

            expect($file->getFilename())->not->toEndWith('.blade.php');
        }

        $ecosystem .= $this->fixture->read('CLAUDE.md');

        expect($output)->not->toContain('$assist')->not->toMatch('/___[A-Z0-9_]+___/')->not->toContain('Laravel\\Boost\\Support')
            ->and($ecosystem)->not->toContain('search-docs')->not->toContain('record-rule')->toContain('afterburner-documentation');
    })->with([
        'Livewire 3 stack' => [
            ['laravel/framework' => '12.3.0', 'livewire/livewire' => '3.6.0', 'livewire/volt' => '1.7.0', 'livewire/flux' => '2.1.0', 'laravel/folio' => '1.1.0', 'laravel/pennant' => '1.16.0', 'laravel/mcp' => '0.9.0', 'laravel/wayfinder' => '0.1.0', 'pestphp/pest' => '4.0.0', 'laravel/pint' => '1.27.0', 'laravel/sail' => '1.40.0'],
            ['tailwindcss' => '3.4.0'],
        ],
        'Livewire 2 and Inertia 1 stack' => [
            ['laravel/framework' => '11.9.0', 'livewire/livewire' => '2.12.0', 'phpunit/phpunit' => '11.0.0', 'inertiajs/inertia-laravel' => '1.3.0'],
            ['tailwindcss' => '4.1.0', '@inertiajs/react' => '1.3.0', '@inertiajs/vue3' => '1.3.0', '@inertiajs/svelte' => '1.3.0'],
        ],
        'Livewire 4 and Inertia 2 stack' => [
            ['laravel/framework' => '13.4.0', 'livewire/livewire' => '4.0.1', 'livewire/flux-pro' => '2.1.0', 'livewire/flux' => '2.1.0', 'inertiajs/inertia-laravel' => '2.0.0'],
            ['@inertiajs/react' => '2.2.0', '@inertiajs/vue3' => '2.1.0', '@inertiajs/svelte' => '2.0.0'],
        ],
        'Inertia 3 stack' => [
            ['laravel/framework' => '13.4.0', 'inertiajs/inertia-laravel' => '3.0.0', 'laravel/wayfinder' => '0.1.0'],
            ['@inertiajs/vue3' => '3.0.0'],
        ],
    ]);

    it('ships the recorded release snapshot, its license, and the documented adaptations', function () {
        $baseline = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/boost-baseline.json'), true);
        $provenance = json_decode(file_get_contents(resourcesPath('boost/PROVENANCE.json')), true);
        $patch = file_get_contents(resourcesPath('boost/ADAPTATIONS.patch'));
        $pristine = pristineBoostSnapshot();

        try {
            $upstream = treeHashes($pristine);
        } finally {
            TemporaryDirectory::remove($pristine);
        }

        $shipped = treeHashes(resourcesPath('boost'));
        unset($shipped['LICENSE.md'], $shipped['PROVENANCE.json'], $shipped['ADAPTATIONS.patch']);
        preg_match_all('/^\+\+\+ b\/(\S+)$/m', $patch, $patched);
        $adapted = $patched[1];
        sort($adapted);

        expect($provenance)->toMatchArray(['package' => 'laravel/boost', 'tag' => $baseline['tag'], 'commit' => $baseline['commit']])
            ->and($provenance['files'])->toBe($upstream)
            ->and(count($upstream))->toBe(77)
            ->and($provenance['adaptations']['files'])->toBe($adapted)
            ->and(array_keys(array_diff_assoc($shipped, $upstream)))->toEqualCanonicalizing($adapted)
            ->and(array_keys($shipped))->toBe(array_keys($upstream))
            ->and(file_get_contents(resourcesPath('boost/LICENSE.md')))->toContain('The MIT License')->toContain('Taylor Otwell');
    });

    it('reads Afterburner configuration and contains nothing specific to Boost\'s MCP server', function (string $catalog) {
        $tools = ['search-docs', 'record-rule', 'get-absolute-url', 'database-query', 'database-schema', 'database-connections', 'browser-logs', 'read-log-entries', 'last-error', 'application-info', 'list-routes', 'list-artisan-commands'];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resourcesPath($catalog), FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! preg_match('/\.(blade\.php|md)$/', $file->getFilename()) || in_array($file->getFilename(), ['LICENSE.md', 'CURATION.md'], true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $name = substr($file->getPathname(), strlen(resourcesPath($catalog)) + 1);

            expect($contents)
                ->not->toContain("config('boost.", $name)
                ->not->toContain('hasMcpEnabled', $name)
                ->not->toContain('Laravel\\Boost', $name)
                ->not->toContain('MCP tool', $name)
                ->not->toContain('Boost tools', $name);

            foreach ($tools as $tool) {
                expect($contents)->not->toContain($tool, "{$name} mentions {$tool}");
            }
        }
    })->with(['boost']);

    it('refreshes the snapshot, reapplies the adaptations, and leaves the curated copy alone', function () {
        $resources = TemporaryDirectory::make('afterburner-resources-');
        $upstream = TemporaryDirectory::make('afterburner-upstream-');
        $pristine = pristineBoostSnapshot();

        try {
            (new Process(['cp', '-a', resourcesPath('.'), $resources.'/resources']))->mustRun();
            file_put_contents($resources.'/resources/afterburner/foundation.blade.php', "# Deliberately curated\n");
            $curatedBefore = treeHashes($resources.'/resources/afterburner');

            (new Process(['cp', '-a', $pristine, $upstream.'/.ai']))->mustRun();
            copy(resourcesPath('boost/LICENSE.md'), $upstream.'/LICENSE.md');
            // A file the adaptations do not touch.
            file_put_contents($upstream.'/.ai/laravel/skill/laravel-best-practices/rules/caching.md', "# Upstream caching v9\n");
            file_put_contents($upstream.'/.ai/new-guideline.blade.php', "# New\n");

            $refresh = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/refresh-boost-snapshot', $upstream, '--tag=v9.9.9', '--commit='.str_repeat('a', 40), '--resources='.$resources.'/resources']);
            $refresh->mustRun();

            $provenance = json_decode(file_get_contents($resources.'/resources/boost/PROVENANCE.json'), true);

            expect($refresh->getOutput())->toContain('Added: 1, changed: 1, removed: 0', 'adaptations reapplied', 'was not modified')
                ->and(file_get_contents($resources.'/resources/boost/laravel/skill/laravel-best-practices/rules/caching.md'))->toBe("# Upstream caching v9\n")
                ->and(file_get_contents($resources.'/resources/boost/foundation.blade.php'))->toContain('$assist->config->purpose')->not->toContain("config('boost.purpose')")
                ->and(file_get_contents($resources.'/resources/boost/ADAPTATIONS.patch'))->toBe(file_get_contents(resourcesPath('boost/ADAPTATIONS.patch')))
                ->and($provenance)->toMatchArray(['tag' => 'v9.9.9', 'commit' => str_repeat('a', 40)])
                ->and($provenance['adaptations']['files'])->toBe(json_decode(file_get_contents(resourcesPath('boost/PROVENANCE.json')), true)['adaptations']['files'])
                ->and(treeHashes($resources.'/resources/afterburner'))->toBe($curatedBefore);

            // An upstream change to adapted lines stops the refresh without touching the snapshot.
            $snapshotBefore = treeHashes($resources.'/resources/boost');
            file_put_contents($upstream.'/.ai/boost/core.blade.php', str_replace('## Tools', '## MCP Tools', file_get_contents($upstream.'/.ai/boost/core.blade.php')));

            $conflict = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/refresh-boost-snapshot', $upstream, '--tag=v9.9.10', '--commit='.str_repeat('b', 40), '--resources='.$resources.'/resources']);
            $conflict->run();

            expect($conflict->getExitCode())->toBe(1)
                ->and($conflict->getErrorOutput())->toContain('adaptations no longer apply', 'boost/core.blade.php')
                ->and(treeHashes($resources.'/resources/boost'))->toBe($snapshotBefore);
        } finally {
            TemporaryDirectory::remove($resources);
            TemporaryDirectory::remove($upstream);
            TemporaryDirectory::remove($pristine);
        }
    });

    it('publishes from shipped files without fetching anything', function () {
        Http::preventStrayRequests();

        $blocked = [];

        foreach (['http', 'https', 'ftp'] as $protocol) {
            if (in_array($protocol, stream_get_wrappers(), true)) {
                stream_wrapper_unregister($protocol);
                stream_wrapper_register($protocol, BlockingStreamWrapper::class);
                $blocked[] = $protocol;
            }
        }

        try {
            $this->fixture->composerPackage('laravel/framework', '12.3.0');

            installPreset('boost', ['integrations' => ['cloud' => true]]);
            installPreset('afterburner');
        } finally {
            foreach ($blocked as $protocol) {
                stream_wrapper_restore($protocol);
            }
        }

        expect(BlockingStreamWrapper::$attempts)->toBe([])
            ->and(publishedSkills())->toContain('laravel-best-practices');
    });
});
