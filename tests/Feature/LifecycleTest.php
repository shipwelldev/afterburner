<?php

declare(strict_types=1);
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\Support\FixtureApplication;

const MANUAL_CLAUDE = "# Team notes for Claude\n\nAsk before running migrations.\n";
const MANUAL_AGENTS = "# Team notes\n\nUse the staging database for experiments.\n";
const USER_RULE = "---\npaths:\n  - 'app/Http/Controllers/**'\n---\n\n# Controllers\n\n## Thin controllers\nMove logic into actions.\n";

function seedApplication(FixtureApplication $fixture): void
{
    $fixture
        ->write('CLAUDE.md', MANUAL_CLAUDE)
        ->write('AGENTS.md', MANUAL_AGENTS)
        ->write('.ai/rules/controllers.md', USER_RULE)
        ->skillAt('.claude/skills/hand-made', 'hand-made', 'Written by the team.')
        ->skillAt('.agents/skills/hand-made', 'hand-made', 'Written by the team.')
        ->guideline('team.md', "Team guideline.\n")
        ->skill('team-skill')
        ->composerPackage('laravel/framework', '12.3.0')
        ->composerPackage('acme/kit', '1.0.0', [
            'resources/boost/guidelines/core.md' => "Kit guideline.\n",
            'resources/boost/skills/kit-skill/SKILL.md' => "---\nname: kit-skill\ndescription: Kit skill.\n---\n",
        ]);
}

function assertUserContentPreserved(FixtureApplication $fixture): void
{
    expect($fixture->read('CLAUDE.md'))->toStartWith(MANUAL_CLAUDE)
        ->and($fixture->read('AGENTS.md'))->toStartWith(MANUAL_AGENTS)
        ->and($fixture->read('.ai/rules/controllers.md'))->toBe(USER_RULE)
        ->and($fixture->exists('.claude/skills/hand-made/SKILL.md'))->toBeTrue()
        ->and($fixture->exists('.agents/skills/hand-made/SKILL.md'))->toBeTrue()
        ->and($fixture->exists('.ai/skills/team-skill/SKILL.md'))->toBeTrue();
}

function skillsIn(FixtureApplication $fixture, string $directory): array
{
    return array_values(array_diff(array_map('basename', glob($fixture->path($directory.'/*'), GLOB_ONLYDIR) ?: []), ['hand-made']));
}

beforeEach(function () {
    seedApplication($this->fixture);
});

it('walks an application through the complete lifecycle while preserving user content', function () {
    // First noninteractive run without agents fails without changing anything.
    $before = $this->fixture->snapshot();

    expect(runCommand('afterburner:install')->exitCode)->toBe(1)
        ->and($this->fixture->snapshot())->toBe($before);

    // Fresh install (the walk uses the Boost copy because the curated copy is edited by hand).
    expect(runCommand('afterburner:install', ['--agents' => 'claude,codex', '--base' => 'boost'])->successful())->toBeTrue();
    assertUserContentPreserved($this->fixture);

    expect($this->fixture->read('CLAUDE.md'))->toContain('=== foundation rules ===', '=== laravel/v12 rules ===', '=== acme/kit/core rules ===', '=== .ai/team rules ===')
        ->and(skillsIn($this->fixture, '.claude/skills'))->toContain('laravel-best-practices', 'kit-skill', 'team-skill', ...CORE_SKILLS)
        ->and(skillsIn($this->fixture, '.agents/skills'))->toBe(skillsIn($this->fixture, '.claude/skills'));

    // A repeated update is idempotent.
    $installed = $this->fixture->snapshot();
    runCommand('afterburner:update');

    expect($this->fixture->snapshot())->toBe($installed);

    // A newly added direct package is included on update.
    $this->fixture->npmPackage('@acme/ui', '2.0.0', ['resources/boost/skills/ui-skill/SKILL.md' => "---\nname: ui-skill\ndescription: UI.\n---\n"]);
    runCommand('afterburner:update');

    expect(skillsIn($this->fixture, '.claude/skills'))->toContain('ui-skill');

    // Changing a relevant major version swaps versioned guidance.
    $this->fixture->composerPackage('laravel/framework', '11.9.0');
    runCommand('afterburner:update');

    expect($this->fixture->read('CLAUDE.md'))->toContain('=== laravel/v11 rules ===')->not->toContain('=== laravel/v12 rules ===');

    // Removing a package removes its managed output.
    $this->fixture->removeComposerPackage('acme/kit');
    runCommand('afterburner:update');

    expect(skillsIn($this->fixture, '.claude/skills'))->not->toContain('kit-skill')
        ->and($this->fixture->read('CLAUDE.md'))->not->toContain('acme/kit/core');

    // Changing the preset removes stale bundled output.
    runCommand('afterburner:install', ['--base' => 'core']);

    expect(skillsIn($this->fixture, '.claude/skills'))->not->toContain('laravel-best-practices')
        ->and($this->fixture->read('CLAUDE.md'))->not->toContain('=== foundation rules ===');
    assertUserContentPreserved($this->fixture);

    // Deselecting Claude removes its managed output only.
    runCommand('afterburner:install', ['--agents' => 'codex,cursor']);

    expect($this->fixture->read('CLAUDE.md'))->toBe(MANUAL_CLAUDE)
        ->and(skillsIn($this->fixture, '.claude/skills'))->toBe([])
        ->and(skillsIn($this->fixture, '.agents/skills'))->toContain('team-skill');

    // Deselecting one shared agent keeps the shared destination.
    runCommand('afterburner:install', ['--agents' => 'cursor']);

    expect(skillsIn($this->fixture, '.agents/skills'))->toContain('team-skill')
        ->and($this->fixture->read('AGENTS.md'))->toContain('<afterburner-guidelines>');

    // Deselecting the last shared agent removes the shared output.
    runCommand('afterburner:install', ['--agents' => 'claude']);

    expect($this->fixture->read('AGENTS.md'))->toBe(MANUAL_AGENTS)
        ->and(skillsIn($this->fixture, '.agents/skills'))->toBe([]);

    // An explicit empty selection cleans up everything managed.
    $configuration = $this->fixture->configuration();
    $configuration['agents'] = [];
    $this->fixture->writeJson('afterburner.json', ['published' => ['skills' => (object) $configuration['published']['skills']] + $configuration['published']] + $configuration);
    runCommand('afterburner:update');

    expect($this->fixture->read('CLAUDE.md'))->toBe(MANUAL_CLAUDE)
        ->and(skillsIn($this->fixture, '.claude/skills'))->toBe([])
        ->and($this->fixture->configuration()['published'])->toBe(['guidelines' => [], 'skills' => [], 'rules' => []]);
    assertUserContentPreserved($this->fixture);
});

it('applies the defaults and explicit preset precedence', function () {
    runCommand('afterburner:install', ['--agents' => 'claude']);

    expect($this->fixture->configuration())->not->toHaveKey('base');

    runCommand('afterburner:install', ['--base' => 'boost']);
    runCommand('afterburner:update');

    expect($this->fixture->configuration()['base'])->toBe('boost')
        ->and($this->fixture->read('CLAUDE.md'))->toContain('fastest way to deploy');
});

it('publishes to every agent destination', function (string $agent, string $document, string $skills) {
    runCommand('afterburner:install', ['--agents' => $agent, '--base' => 'boost']);

    expect($this->fixture->read($document))->toContain('=== foundation rules ===')
        ->and(skillsIn($this->fixture, $skills))->toContain('infer-conventions', 'laravel-best-practices', 'kit-skill');
})->with([
    ['claude', 'CLAUDE.md', '.claude/skills'],
    ['codex', 'AGENTS.md', '.agents/skills'],
    ['cursor', 'AGENTS.md', '.agents/skills'],
    ['pi', 'AGENTS.md', '.agents/skills'],
    ['opencode', 'AGENTS.md', '.agents/skills'],
]);

it('respects every independent package switch on update', function (array $switches, bool $guidelines, bool $skills) {
    runCommand('afterburner:install', ['--agents' => 'claude', '--base' => 'none']);

    $this->fixture->reconfigure(['agents' => ['claude'], 'base' => 'none', 'packages' => ['acme/kit' => (object) $switches]]);
    runCommand('afterburner:update');

    expect(str_contains($this->fixture->read('CLAUDE.md'), 'acme/kit/core'))->toBe($guidelines)
        ->and(in_array('kit-skill', skillsIn($this->fixture, '.claude/skills'), true))->toBe($skills);
})->with([
    [[], true, true],
    [['guidelines' => false], false, true],
    [['skills' => false], true, false],
    [['guidelines' => false, 'skills' => false], false, false],
]);

it('keeps prior output and tracking when a selected asset is invalid, but ignores excluded ones', function () {
    runCommand('afterburner:install', ['--agents' => 'claude,codex', '--base' => 'boost']);
    $before = $this->fixture->snapshot();

    $this->fixture->write('vendor/acme/kit/resources/boost/skills/broken/SKILL.blade.php', "{{ throw new RuntimeException('broken kit') }}\n");
    $broken = $this->fixture->snapshot();

    $result = runCommand('afterburner:update');

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('broken kit')
        ->and($this->fixture->snapshot())->toBe($broken);

    $this->fixture->reconfigure(['agents' => ['claude', 'codex'], 'base' => 'boost', 'packages' => ['acme/kit' => ['skills' => false]]]);

    expect(runCommand('afterburner:update')->successful())->toBeTrue()
        ->and(skillsIn($this->fixture, '.claude/skills'))->not->toContain('kit-skill');
    assertUserContentPreserved($this->fixture);

    expect(array_diff_key($before, $this->fixture->snapshot()))->toHaveKey('.claude/skills/kit-skill/SKILL.md');
});

it('runs the same operation through the compatibility entry points', function (string $canonical, string $alias, array $parameters) {
    runCommand('afterburner:install', ['--agents' => 'claude,codex', '--base' => 'boost']);
    $this->fixture->guideline('changed.md', "Changed.\n");

    $first = runCommand($canonical, $parameters);
    $afterCanonical = $this->fixture->snapshot();

    $this->fixture->delete('.claude/skills/infer-conventions');
    runCommand('afterburner:install', ['--base' => 'afterburner']);
    runCommand('afterburner:install', ['--base' => 'boost']);
    $this->fixture->delete('.ai/guidelines/changed.md');
    runCommand('afterburner:update');
    $this->fixture->guideline('changed.md', "Changed.\n");

    $second = runCommand($alias, $parameters);

    expect($second->exitCode)->toBe($first->exitCode)
        ->and($this->fixture->snapshot())->toBe($afterCanonical)
        ->and(preg_replace('/\s+/', ' ', $second->output))->toBe(preg_replace('/\s+/', ' ', $first->output));
})->with([
    ['afterburner:install', 'boost:install', ['--base' => 'boost']],
    ['afterburner:update', 'boost:update', []],
]);

it('rejects historical Boost-only options instead of running hidden tooling', function (string $command, string $option) {
    runCommand('afterburner:install', ['--agents' => 'claude', '--base' => 'none']);
    $before = $this->fixture->snapshot();

    expect(fn () => runCommand($command, [$option => true]))->toThrow(InvalidOptionException::class, "The \"{$option}\" option does not exist.")
        ->and($this->fixture->snapshot())->toBe($before);
})->with([
    ['boost:install', '--mcp'],
    ['boost:install', '--guidelines'],
    ['boost:update', '--discover'],
    ['boost:update', '--ignore-skills'],
]);

it('does not prompt during Composer scripts', function () {
    $this->environment->set('COMPOSER_DEV_MODE', '1');
    $this->fixture->delete('AGENTS.md');

    $this->artisan('afterburner:install')
        ->expectsOutputToContain('No agents are selected')
        ->assertFailed();
});

it('never migrates Boost during an ordinary install or update', function () {
    $this->fixture
        ->composerPackage('laravel/boost', '2.10.4', dev: true)
        ->writeJson('boost.json', ['agents' => ['claude_code'], 'guidelines' => true, 'skills' => ['laravel-best-practices']])
        ->writeJson('.mcp.json', ['mcpServers' => ['laravel-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']]]])
        ->write('CLAUDE.md', MANUAL_CLAUDE."\n<laravel-boost-guidelines>\nOld Boost guidance.\n</laravel-boost-guidelines>\n")
        ->skillAt('.claude/skills/laravel-best-practices', 'laravel-best-practices', 'Published by Boost.');

    $untouched = ['boost.json', '.mcp.json', 'composer.json', 'composer.lock', '.claude/skills/laravel-best-practices/SKILL.md'];
    $before = array_map(fn ($file) => $this->fixture->read($file), array_combine($untouched, $untouched));

    expect(runCommand('afterburner:install', ['--agents' => 'claude', '--base' => 'none'])->successful())->toBeTrue()
        ->and(runCommand('afterburner:update')->successful())->toBeTrue()
        ->and(array_map(fn ($file) => $this->fixture->read($file), array_combine($untouched, $untouched)))->toBe($before)
        ->and($this->fixture->read('CLAUDE.md'))->toContain("<laravel-boost-guidelines>\nOld Boost guidance.\n</laravel-boost-guidelines>", '<afterburner-guidelines>');
});
