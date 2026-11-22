<?php

declare(strict_types=1);
use Tests\Support\CommandResult;

/**
 * @return array<string, string> guideline identity => body
 */
function guidelineSections(string $document): array
{
    preg_match('/<afterburner-guidelines>\n(.*?)\n\n<\/afterburner-guidelines>/s', $document, $block);
    preg_match_all('/^=== (.+?) rules ===\n\n(.*?)(?=\n\n=== |\z)/ms', $block[1] ?? '', $matches, PREG_SET_ORDER);

    $sections = [];

    foreach ($matches as $match) {
        $sections[$match[1]] = trim($match[2]);
    }

    return $sections;
}

function skillFile(string $name, string $description = 'A skill.', string $body = ''): string
{
    return "---\nname: {$name}\ndescription: {$description}\n---\n\n".($body ?: "# {$name}\n");
}

function publish(array $parameters = []): CommandResult
{
    return runCommand('afterburner:install', ['--agents' => 'claude', '--base' => 'none', ...$parameters]);
}

it('keeps same-basename and nested guidelines from different packages apart', function () {
    $this->fixture
        ->composerPackage('acme/a', '1.2.0', [
            'resources/boost/guidelines/core.md' => "Package A core.\n",
            'resources/boost/guidelines/admin/core.md' => "Package A admin.\n",
            'resources/boost/guidelines/api/core.blade.php.txt' => 'ignored',
        ])
        ->composerPackage('acme/b', '3.0.0', [
            'resources/boost/guidelines/core.md' => "Package B core.\n",
            'resources/boost/guidelines/api/core.md' => "Package B api.\n",
        ])
        ->npmPackage('@acme/ui', '2.0.0', [
            'resources/boost/guidelines/core.md' => "UI core.\n",
        ]);

    expect(publish()->successful())->toBeTrue()
        ->and(guidelineSections($this->fixture->read('CLAUDE.md')))->toBe([
            'acme/a/admin/core' => 'Package A admin.',
            'acme/a/core' => 'Package A core.',
            'acme/b/api/core' => 'Package B api.',
            'acme/b/core' => 'Package B core.',
            '@acme/ui/core' => 'UI core.',
        ]);
});

it('resolves same-name dependency skills by incoming package order', function (array $order, string $winner) {
    foreach ($order as $package) {
        $this->fixture->composerPackage($package, '1.0.0', [
            'resources/boost/skills/shared/SKILL.md' => skillFile('shared', "From {$package}."),
        ]);
    }

    expect(publish()->successful())->toBeTrue()
        ->and($this->fixture->read('.claude/skills/shared/SKILL.md'))->toContain("From {$winner}.");
})->with([
    'a before z' => [['acme/a', 'acme/z'], 'acme/z'],
    'z before a' => [['acme/z', 'acme/a'], 'acme/a'],
]);

it('lets JavaScript package skills follow PHP package skills', function () {
    $this->fixture
        ->npmPackage('@acme/ui', '1.0.0', ['resources/boost/skills/shared/SKILL.md' => skillFile('shared', 'From npm.')])
        ->composerPackage('acme/z', '1.0.0', ['resources/boost/skills/shared/SKILL.md' => skillFile('shared', 'From composer.')]);

    publish();

    expect($this->fixture->read('.claude/skills/shared/SKILL.md'))->toContain('From npm.');
});

it('gives application skills final precedence over dependency skills', function () {
    $this->fixture
        ->composerPackage('acme/a', '2.1.0', ['resources/boost/skills/shared/SKILL.md' => skillFile('shared', 'From the vendor.')])
        ->skill('shared', 'From the application.', directory: 'my-shared');

    publish();

    expect($this->fixture->read('.claude/skills/shared/SKILL.md'))->toContain('From the application.');
});

it('replaces a dependency guideline with the application guideline of the same full identity', function () {
    $this->fixture
        ->composerPackage('acme/a', '1.0.0', ['resources/boost/guidelines/core.md' => "Vendor A.\n"])
        ->composerPackage('acme/b', '1.0.0', ['resources/boost/guidelines/core.md' => "Vendor B.\n"])
        ->guideline('acme/a/core.md', "Application A.\n")
        ->guideline('notes.md', "Notes.\n");

    publish();

    expect(guidelineSections($this->fixture->read('CLAUDE.md')))->toBe([
        '.ai/notes' => 'Notes.',
        'acme/a/core' => 'Application A.',
        'acme/b/core' => 'Vendor B.',
    ]);
});

it('selects package-specific application skills for the installed major version only', function () {
    $this->fixture
        ->composerPackage('acme/my_pkg', '2.4.1')
        ->skillAt('.ai/acme-my-pkg/skill/helper', 'helper', 'Unversioned helper.')
        ->skillAt('.ai/acme-my-pkg/skill/general', 'general', 'General help.')
        ->skillAt('.ai/acme-my-pkg/2/skill/helper', 'helper', 'Version 2 helper.')
        ->skillAt('.ai/acme-my-pkg/3/skill/future', 'future', 'Version 3 only.')
        ->skillAt('.ai/acme-absent/skill/absent', 'absent', 'Package not installed.')
        ->npmPackage('@acme/ui', '5.0.0')
        ->skillAt('.ai/acme-ui/5/skill/ui-helper', 'ui-helper', 'UI helper.');

    publish();

    expect($this->fixture->read('.claude/skills/helper/SKILL.md'))->toContain('Version 2 helper.')
        ->and($this->fixture->exists('.claude/skills/general/SKILL.md'))->toBeTrue()
        ->and($this->fixture->exists('.claude/skills/ui-helper/SKILL.md'))->toBeTrue()
        ->and($this->fixture->exists('.claude/skills/future'))->toBeFalse()
        ->and($this->fixture->exists('.claude/skills/absent'))->toBeFalse();
});

it('does not fall back to another major version directory', function () {
    $this->fixture
        ->composerPackage('acme/a', '4.0.0')
        ->skillAt('.ai/acme-a/3/skill/old', 'old', 'Version 3.');

    publish();

    expect($this->fixture->exists('.claude/skills/old'))->toBeFalse();
});

it('uses first-party package names and only their core guideline', function () {
    $this->fixture
        ->composerPackage('livewire/flux', '2.0.0', [
            'resources/boost/guidelines/core.md' => "Flux vendor core.\n",
            'resources/boost/guidelines/extra.md' => "Not a third-party guideline.\n",
            'resources/boost/skills/flux-skill/SKILL.md' => skillFile('flux-skill'),
        ])
        ->skillAt('.ai/fluxui-free/2/skill/flux-app', 'flux-app', 'App skill for Flux 2.');

    publish();

    expect(guidelineSections($this->fixture->read('CLAUDE.md')))->toBe(['fluxui-free/core' => 'Flux vendor core.'])
        ->and($this->fixture->exists('.claude/skills/flux-skill'))->toBeTrue()
        ->and($this->fixture->exists('.claude/skills/flux-app'))->toBeTrue();
});

it('applies the baseline package suppression rules', function () {
    $this->fixture
        ->composerPackage('pestphp/pest', '4.0.0', ['resources/boost/guidelines/core.md' => "Pest.\n"], dev: true)
        ->composerPackage('phpunit/phpunit', '12.0.0', ['resources/boost/guidelines/core.md' => "PHPUnit.\n"], direct: false, dev: true)
        ->composerPackage('laravel/mcp', '1.0.0', ['resources/boost/guidelines/core.md' => "MCP.\n"], direct: false)
        ->composerPackage('laravel/boost', '2.10.4', [
            'resources/boost/guidelines/core.md' => "Boost.\n",
            'resources/boost/skills/boost-skill/SKILL.md' => skillFile('boost-skill'),
        ], dev: true);

    publish();

    expect(guidelineSections($this->fixture->read('CLAUDE.md')))->toBe(['pest/core' => 'Pest.'])
        ->and($this->fixture->exists('.claude/skills/boost-skill'))->toBeFalse();
});

it('ignores assets from transitive third-party dependencies', function () {
    $this->fixture
        ->composerPackage('acme/direct', '1.0.0', ['resources/boost/guidelines/core.md' => "Direct.\n"])
        ->composerPackage('acme/transitive', '1.0.0', [
            'resources/boost/guidelines/core.md' => "Transitive.\n",
            'resources/boost/skills/transitive/SKILL.md' => skillFile('transitive'),
        ], direct: false)
        ->npmPackage('acme-transitive-js', '1.0.0', ['resources/boost/skills/js/SKILL.md' => skillFile('js-transitive')], direct: false)
        ->composerPackage('acme/no-assets', '1.0.0');

    publish();

    expect(guidelineSections($this->fixture->read('CLAUDE.md')))->toBe(['acme/direct/core' => 'Direct.'])
        ->and($this->fixture->exists('.claude/skills'))->toBeFalse();
});

it('resolves installed packages from the configured vendor directory', function () {
    $this->fixture->composerPackage('acme/a', '1.0.0');
    $manifest = $this->fixture->json('composer.json');
    $manifest['config'] = ['vendor-dir' => 'libraries'];
    $this->fixture->writeJson('composer.json', $manifest);
    $this->fixture->write('libraries/acme/a/resources/boost/guidelines/core.md', "From libraries.\n");
    $this->fixture->write('vendor/acme/a/resources/boost/guidelines/core.md', "From vendor.\n");

    publish();

    expect(guidelineSections($this->fixture->read('CLAUDE.md')))->toBe(['acme/a/core' => 'From libraries.']);
});

it('filters dependency guidelines and skills independently', function (array $switches, bool $guidelines, bool $skills) {
    $this->fixture
        ->composerPackage('acme/a', '1.0.0', [
            'resources/boost/guidelines/core.md' => "Vendor A.\n",
            'resources/boost/skills/vendor-skill/SKILL.md' => skillFile('vendor-skill'),
        ])
        ->composerPackage('acme/b', '1.0.0', [
            'resources/boost/guidelines/core.md' => "Vendor B.\n",
            'resources/boost/skills/other-skill/SKILL.md' => skillFile('other-skill'),
        ])
        ->configure(['packages' => ['acme/a' => (object) $switches]]);

    expect(publish()->successful())->toBeTrue();

    $sections = guidelineSections($this->fixture->read('CLAUDE.md'));

    expect(array_key_exists('acme/a/core', $sections))->toBe($guidelines)
        ->and($sections['acme/b/core'])->toBe('Vendor B.')
        ->and($this->fixture->exists('.claude/skills/vendor-skill'))->toBe($skills)
        ->and($this->fixture->exists('.claude/skills/other-skill'))->toBeTrue();
})->with([
    'defaults' => [[], true, true],
    'guidelines off' => [['guidelines' => false], false, true],
    'skills off' => [['skills' => false], true, false],
    'both off' => [['guidelines' => false, 'skills' => false], false, false],
    'explicitly on' => [['guidelines' => true, 'skills' => true], true, true],
]);

it('keeps application content for excluded packages', function () {
    $this->fixture
        ->composerPackage('acme/a', '1.0.0', [
            'resources/boost/guidelines/core.md' => "Vendor A.\n",
            'resources/boost/skills/shared/SKILL.md' => skillFile('shared', 'Vendor.'),
        ])
        ->guideline('acme/a/core.md', "Application A.\n")
        ->skillAt('.ai/acme-a/skill/app-helper', 'app-helper', 'Application helper.')
        ->configure(['packages' => ['acme/a' => ['guidelines' => false, 'skills' => false]]]);

    publish();

    expect(guidelineSections($this->fixture->read('CLAUDE.md')))->toBe(['.ai/acme/a/core' => 'Application A.'])
        ->and($this->fixture->exists('.claude/skills/shared'))->toBeFalse()
        ->and($this->fixture->exists('.claude/skills/app-helper'))->toBeTrue();
});

it('does not read malformed content from excluded packages', function () {
    $this->fixture
        ->composerPackage('acme/broken', '1.0.0', [
            'resources/boost/guidelines/core.md' => "Fine.\n",
            'resources/boost/skills/broken/SKILL.md' => "no frontmatter\n",
        ])
        ->composerPackage('acme/unreadable', '1.0.0', [
            'resources/boost/guidelines/core.md' => "Secret.\n",
        ])
        ->configure(['packages' => [
            'acme/broken' => ['skills' => false],
            'acme/unreadable' => ['guidelines' => false],
        ]]);

    chmod($this->fixture->path('vendor/acme/unreadable/resources/boost/guidelines/core.md'), 0000);

    expect(publish()->successful())->toBeTrue();

    $this->fixture->configure(['packages' => new stdClass]);
    $result = publish();

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('vendor/acme');
});

it('cleans up managed output when a dependency is removed', function () {
    $this->fixture
        ->composerPackage('acme/a', '1.0.0', [
            'resources/boost/guidelines/core.md' => "Vendor A.\n",
            'resources/boost/skills/vendor-skill/SKILL.md' => skillFile('vendor-skill'),
        ])
        ->skill('local');

    publish();

    expect($this->fixture->exists('.claude/skills/vendor-skill'))->toBeTrue();

    $this->fixture->removeComposerPackage('acme/a');

    expect(runCommand('afterburner:update')->successful())->toBeTrue()
        ->and($this->fixture->exists('.claude/skills/vendor-skill'))->toBeFalse()
        ->and($this->fixture->exists('.claude/skills/local'))->toBeTrue()
        ->and($this->fixture->exists('CLAUDE.md'))->toBeFalse()
        ->and($this->fixture->configuration()['published']['skills'])->toBe(['.claude/skills' => ['local']]);
});

it('includes newly added dependencies on update', function () {
    $this->fixture->skill('local');
    publish();

    $this->fixture->composerPackage('acme/new', '1.0.0', [
        'resources/boost/skills/new-skill/SKILL.md' => skillFile('new-skill'),
    ]);

    runCommand('afterburner:update');

    expect($this->fixture->exists('.claude/skills/new-skill/SKILL.md'))->toBeTrue();
});
