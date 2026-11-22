<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\TemporaryDirectory;

const BOOST_TOOLS = [
    'search-docs', 'record-rule', 'database-query', 'database-schema', 'database-connections',
    'get-absolute-url', 'tinker', 'read-log-entries', 'last-error', 'browser-logs', 'application-info',
    'list-routes', 'list-artisan-commands', 'get-config', 'list-available-config-keys', 'list-available-env-vars',
];

function publishCore(string $base = 'core', array $agents = ['claude']): void
{
    $result = runCommand('afterburner:install', ['--agents' => implode(',', $agents), '--base' => $base]);

    if (! $result->successful()) {
        throw new RuntimeException($result->output);
    }
}

function coreSkill(string $name): string
{
    return test()->fixture->read(".claude/skills/{$name}/SKILL.md");
}

/**
 * @return array<string, mixed>
 */
function frontmatterOf(string $skill): array
{
    preg_match('/^---\n(.*?)\n---\n/s', $skill, $matches);

    return Yaml::parse($matches[1] ?? '') ?? [];
}

it('publishes the core skills for every preset that includes them', function (string $base) {
    publishCore($base);

    foreach (CORE_SKILLS as $name) {
        $skill = coreSkill($name);
        $metadata = frontmatterOf($skill);

        expect($metadata['name'])->toBe($name)
            ->and(strlen($metadata['description']))->toBeGreaterThan(80)
            ->and($skill)->not->toContain('{{')->not->toContain('@php')->not->toContain('@if');
    }
})->with(['core', 'boost', 'afterburner']);

it('publishes nothing but the core skills for the core preset', function () {
    $this->fixture
        ->composerPackage('laravel/framework', '12.3.0')
        ->composerPackage('livewire/livewire', '3.6.0')
        ->npmPackage('tailwindcss', '4.1.0');

    publishCore('core', ['claude', 'codex']);

    expect(array_map('basename', glob($this->fixture->path('.claude/skills/*'), GLOB_ONLYDIR)))->toBe(CORE_SKILLS)
        ->and(array_map('basename', glob($this->fixture->path('.agents/skills/*'), GLOB_ONLYDIR)))->toBe(CORE_SKILLS)
        ->and($this->fixture->exists('CLAUDE.md'))->toBeFalse()
        ->and($this->fixture->exists('AGENTS.md'))->toBeFalse();
});

it('ships core content that is original to Afterburner', function () {
    $root = dirname(__DIR__, 2).'/resources';
    $files = fn (string $directory): array => iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)));
    $words = fn (string $text): array => preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
    $sequences = fn (array $words): array => array_map(fn (int $i): string => implode(' ', array_slice($words, $i, 8)), range(0, max(0, count($words) - 8)));

    $boost = [];
    $pristine = pristineBoostSnapshot();

    foreach ($files($pristine) as $file) {
        $boost += array_flip($sequences($words(file_get_contents($file->getPathname()))));
    }

    TemporaryDirectory::remove($pristine);

    $core = $files($root.'/core');

    expect(array_map(fn ($file) => substr($file->getPathname(), strlen($root.'/core/')), $core))
        ->each->toMatch('#^skills/afterburner-[a-z-]+/SKILL\.blade\.php$#');

    foreach ($core as $file) {
        $shared = array_values(array_filter($sequences($words(file_get_contents($file->getPathname()))), fn (string $sequence) => isset($boost[$sequence])));

        expect($shared)->toBe([], $file->getFilename().' repeats Boost text: '.($shared[0] ?? ''));
    }
});

it('publishes no bundled skills or bootstrap for the none preset', function () {
    $this->fixture->skill('local');

    publishCore('none');

    expect(glob($this->fixture->path('.claude/skills/*'), GLOB_ONLYDIR))->toBe([$this->fixture->path('.claude/skills/local')])
        ->and($this->fixture->exists('CLAUDE.md'))->toBeFalse();
});

it('keeps the Boost tool mapping in the compatibility skill, not in standing guidance', function () {
    $this->fixture->guideline('team.md', "Team guidance.\n");

    publishCore();

    $skill = coreSkill('afterburner-boost-tool-compatibility');
    $metadata = frontmatterOf($skill);
    $guidelines = $this->fixture->read('CLAUDE.md');

    expect($metadata['description'])->toContain('Laravel Boost MCP tool')->toContain('guidelines, skills, or documentation tell you to call one');

    foreach (BOOST_TOOLS as $tool) {
        expect($skill)->toContain("`{$tool}`")
            ->and($metadata['description'])->toContain($tool)
            ->and($guidelines)->not->toContain("`{$tool}`");
    }

    expect($skill)->toContain('never claim a Boost tool ran')->toContain('say that the tool is not available');
});

it('only routes to skills and Artisan commands that exist', function () {
    publishCore();

    $commands = array_keys(Artisan::all());
    $assumed = ['tinker', 'pail'];

    foreach (CORE_SKILLS as $name) {
        $skill = coreSkill($name);

        preg_match_all('/`(afterburner-[a-z-]+|infer-conventions|[a-z]+-[a-z-]+-(?:development|practices))`/', $skill, $skills);

        foreach ($skills[1] as $referenced) {
            expect(CORE_SKILLS)->toContain($referenced);
        }

        preg_match_all('/php artisan ([a-z]+(?::[a-z:-]+)?)/', $skill, $artisan);

        foreach (array_unique($artisan[1]) as $command) {
            if (! in_array($command, $assumed, true)) {
                expect($commands)->toContain($command);
            }
        }
    }
});

it('maps each Boost workflow to an existing tool or command', function (string $skill, array $expected) {
    publishCore();

    expect(coreSkill($skill))->toContain(...$expected);
})->with([
    'search the docs' => ['afterburner-documentation', ['composer show --direct', 'your web search, fetch, or research tools', 'https://laravel.com/docs/<major>.x/<topic>', 'If you have no web or research tool, say so']],
    'remember a controller convention' => ['afterburner-project-rules', ['`app/Http/Controllers/**`', '`.ai/rules`', 'php artisan afterburner:index-rules', 'Keep the existing frontmatter and entries', 'Do not edit `.ai/rules/index.md`']],
    'find the rules for a file' => ['afterburner-project-rules', ['`.ai/rules/index.md`', 'every row whose globs match', 'Several rows can match one path']],
    'inspect routes' => ['afterburner-application-inspection', ['php artisan route:list', '--except-vendor']],
    'query a named database' => ['afterburner-application-inspection', ['php artisan afterburner:database:query "SELECT count(*) AS total FROM orders" --connection=reporting', 'read-only transaction']],
    'database structure' => ['afterburner-application-inspection', ['php artisan db:show', 'php artisan db:table users']],
    'generate a runtime URL' => ['afterburner-application-inspection', ["php artisan tinker --execute 'echo url(\"/dashboard\");'", 'Generate URLs with Laravel']],
    'inspect a browser error' => ['afterburner-debugging', ['your own browser or computer-use tool', 'Do not install a log collector', 'If you do not have a browser or computer-use tool, say so']],
    'read the last error' => ['afterburner-debugging', ['php artisan config:show logging.default', "grep -n '\\.ERROR:' storage/logs/laravel.log | tail -n 1"]],
]);

it('leaves convention inference to the ecosystem presets', function () {
    publishCore('core');

    expect($this->fixture->exists('.claude/skills/infer-conventions'))->toBeFalse();

    publishCore('boost');

    expect(glob($this->fixture->path('.claude/skills/*infer*'), GLOB_ONLYDIR))->toHaveCount(1)
        ->and(frontmatterOf(coreSkill('infer-conventions'))['metadata']['author'])->toBe('laravel')
        ->and($this->fixture->read('.claude/skills/infer-conventions/references/checklist.md'))->toContain('# Detection checklist');
});

it('renders core commands for the application environment', function () {
    $this->fixture->configure(['integrations' => ['sail' => true]]);

    publishCore();

    expect(coreSkill('afterburner-application-inspection'))
        ->toContain('vendor/bin/sail artisan route:list')
        ->toContain('vendor/bin/sail composer show --direct')
        ->not->toContain('php artisan route:list');
});

it('mentions Pail only when it is installed', function (bool $installed) {
    if ($installed) {
        $this->fixture->composerPackage('laravel/pail', '1.3.0', dev: true);
    }

    publishCore();

    expect(str_contains(coreSkill('afterburner-debugging'), 'php artisan pail'))->toBe($installed);
})->with([true, false]);

it('lets application sources replace core skills', function () {
    $this->fixture->skill('afterburner-debugging', 'Our own debugging workflow.', "# Debugging\n\nUse our log viewer.\n");

    publishCore();

    expect(coreSkill('afterburner-debugging'))->toContain('Use our log viewer.');
});

it('removes core output when switching to the none preset', function () {
    $this->fixture->skill('local');
    publishCore('core');

    expect($this->fixture->exists('.claude/skills/afterburner-debugging'))->toBeTrue();

    publishCore('none');

    expect($this->fixture->exists('.claude/skills/afterburner-debugging'))->toBeFalse()
        ->and($this->fixture->exists('.claude/skills/local'))->toBeTrue()
        ->and($this->fixture->exists('CLAUDE.md'))->toBeFalse();
});
