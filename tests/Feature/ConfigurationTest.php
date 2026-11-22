<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * The user preferences in afterburner.json, without generated publication state.
 */
function preferences(): array
{
    return Arr::except(test()->fixture->configuration(), 'published');
}

it('requires an explicit agent selection for a fresh noninteractive install', function () {
    $result = runCommand('afterburner:install');

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('No agents are selected', '--agents', 'afterburner.json');

    expect($this->fixture->exists('afterburner.json'))->toBeFalse();
});

it('saves agents given on the command line in a stable order', function () {
    $this->artisan('afterburner:install', ['--agents' => 'codex,claude,codex', '--no-interaction' => true])
        ->assertSuccessful();

    expect(preferences())->toBe(['agents' => ['claude', 'codex']]);
});

it('rejects unknown and blank command line agents without writing', function (string $agents, string $message) {
    $this->artisan('afterburner:install', ['--agents' => $agents, '--no-interaction' => true])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect($this->fixture->exists('afterburner.json'))->toBeFalse();
})->with([
    'unknown agent' => ['claude,copilot', 'unknown agent "copilot"'],
    'blank token' => ['claude,,codex', 'blank agent identifier'],
    'only blank' => [' ', 'blank agent identifier'],
    'boost identifier' => ['claude_code', 'unknown agent "claude_code"'],
]);

it('reuses configured agents without prompting or rewriting the file', function () {
    $this->fixture->configure(['agents' => ['cursor', 'pi', 'opencode'], 'base' => 'core']);

    $this->artisan('afterburner:install')
        ->expectsOutputToContain('cursor, pi, opencode')
        ->assertSuccessful();

    $before = $this->fixture->read('afterburner.json');

    $this->artisan('afterburner:install')->assertSuccessful();

    expect($this->fixture->read('afterburner.json'))->toBe($before)
        ->and(preferences())->toBe(['agents' => ['cursor', 'pi', 'opencode'], 'base' => 'core']);
});

it('keeps an explicitly saved empty agent selection instead of detecting agents', function () {
    $this->fixture->configure(['agents' => []]);
    $this->fixture->write('CLAUDE.md', "# Notes\n");
    $this->fixture->write('.codex/config.toml', '');

    $result = runCommand('afterburner:install');

    expect($result->successful())->toBeTrue()
        ->and($result->output)->toMatch('/Agents\W+none/')
        ->and(preferences())->toBe(['agents' => []]);
});

it('offers detected agents and presets during first interactive setup and saves the choices', function () {
    $this->fixture->write('.cursor/rules/example.mdc', '');

    $this->artisan('afterburner:install')
        ->expectsChoice(
            'Which agents should Afterburner publish guidelines and skills for?',
            ['claude', 'cursor'],
            ['claude' => 'Claude Code', 'codex' => 'Codex', 'cursor' => 'Cursor', 'pi' => 'Pi', 'opencode' => 'OpenCode'],
        )
        ->expectsChoice(
            'Which base preset should Afterburner use?',
            'core',
            [
                'boost' => 'boost — core skills plus the released Laravel Boost guidance',
                'afterburner' => 'afterburner — core skills plus Afterburner\'s curated Boost guidance',
                'core' => 'core — Afterburner\'s core skills that replace Boost\'s MCP tools, nothing else',
                'none' => 'none — no bundled guidance; application and package content only',
            ],
        )
        ->assertSuccessful();

    expect(preferences())->toBe(['agents' => ['claude', 'cursor'], 'base' => 'core']);
});

it('only prompts for choices that have not been saved', function () {
    $this->fixture->configure(['agents' => ['codex']]);

    $this->artisan('afterburner:install')
        ->expectsChoice('Which base preset should Afterburner use?', 'none', [
            'boost' => 'boost — core skills plus the released Laravel Boost guidance',
            'afterburner' => 'afterburner — core skills plus Afterburner\'s curated Boost guidance',
            'core' => 'core — Afterburner\'s core skills that replace Boost\'s MCP tools, nothing else',
            'none' => 'none — no bundled guidance; application and package content only',
        ])
        ->assertSuccessful();

    expect(preferences())->toBe(['agents' => ['codex'], 'base' => 'none']);

    $this->artisan('afterburner:install')->assertSuccessful();
});

it('uses the afterburner preset by default without saving it', function () {
    $this->artisan('afterburner:install', ['--agents' => 'claude', '--no-interaction' => true])
        ->expectsOutputToContain('afterburner (default)')
        ->assertSuccessful();

    expect(preferences())->not->toHaveKey('base');
});

it('accepts and saves every preset', function (string $preset) {
    $this->artisan('afterburner:install', ['--agents' => 'claude', '--base' => $preset, '--no-interaction' => true])
        ->expectsOutputToContain("{$preset} (selected)")
        ->assertSuccessful();

    expect(preferences()['base'])->toBe($preset);

    $this->artisan('afterburner:install', ['--no-interaction' => true])
        ->expectsOutputToContain("{$preset} (saved)")
        ->assertSuccessful();
})->with(['boost', 'afterburner', 'core', 'none']);

it('rejects an unknown preset option', function () {
    $this->artisan('afterburner:install', ['--agents' => 'claude', '--base' => 'everything', '--no-interaction' => true])
        ->expectsOutputToContain('unknown preset "everything"')
        ->assertFailed();

    expect($this->fixture->exists('afterburner.json'))->toBeFalse();
});

it('does not change the default merely because Boost files exist', function () {
    $this->fixture->writeJson('boost.json', ['agents' => ['claude_code'], 'guidelines' => true]);
    $this->fixture->write('CLAUDE.md', "<laravel-boost-guidelines>\nold\n</laravel-boost-guidelines>\n");
    $this->fixture->composerPackage('laravel/boost', '2.10.4', dev: true);

    $this->artisan('afterburner:install', ['--agents' => 'claude', '--no-interaction' => true])
        ->expectsOutputToContain('afterburner (default)')
        ->assertSuccessful();

    expect($this->fixture->read('CLAUDE.md'))->toContain('<laravel-boost-guidelines>');
});

it('reports invalid existing configuration without changing it', function (string $contents, string $message) {
    $this->fixture->write('afterburner.json', $contents);

    $this->artisan('afterburner:install', ['--agents' => 'claude', '--no-interaction' => true])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect($this->fixture->read('afterburner.json'))->toBe($contents);
})->with([
    'malformed JSON' => ['{"agents": ["claude",]}', 'not valid JSON'],
    'top-level list' => ['["claude"]', 'top level must be a JSON object'],
    'unknown preset' => ['{"base": "boosted"}', '`base`: "boosted" is not one of boost, afterburner, core, none'],
    'preset type' => ['{"base": 1}', '`base`'],
    'agents type' => ['{"agents": "claude"}', '`agents`: expected a list'],
    'unknown agent' => ['{"agents": ["claude", "copilot"]}', '`agents[1]`: "copilot" is not a supported agent'],
    'blank agent' => ['{"agents": [""]}', '`agents[0]`'],
    'packages type' => ['{"packages": ["acme/a"]}', '`packages`: expected an object'],
    'switch type' => ['{"packages": {"acme/a": {"skills": "no"}}}', '`packages.acme/a.skills`: expected true or false, found "no"'],
    'switch name' => ['{"packages": {"acme/a": {"guideline": false}}}', '`packages.acme/a.guideline`'],
    'package entry type' => ['{"packages": {"acme/a": false}}', '`packages.acme/a`'],
    'rules type' => ['{"rules": true}', '`rules`: expected an object'],
    'scoped type' => ['{"rules": {"scoped_guidelines": "yes"}}', '`rules.scoped_guidelines`: expected true or false'],
    'integration type' => ['{"integrations": {"sail": 1}}', '`integrations.sail`'],
    'purpose type' => ['{"purpose": 5}', '`purpose`: expected a string'],
    'published type' => ['{"published": []}', '`published`: expected an object'],
    'published path' => ['{"published": {"guidelines": ["../CLAUDE.md"]}}', '`published.guidelines`'],
]);

it('accepts partial package switches', function () {
    $configuration = [
        'agents' => ['claude'],
        'packages' => [
            'acme/guides' => ['guidelines' => false],
            '@acme/ui' => ['skills' => false],
            'acme/both' => ['guidelines' => true, 'skills' => true],
            'acme/empty' => new stdClass,
        ],
    ];

    $this->fixture->configure($configuration);

    $this->artisan('afterburner:install', ['--no-interaction' => true])->assertSuccessful();
});

it('preserves unrelated keys and publication state when saving choices', function () {
    $contents = <<<'JSON'
{
    "team_note": "keep me",
    "extra": {},
    "packages": {
        "acme/guides": {
            "guidelines": false
        }
    },
    "rules": {
        "scoped_guidelines": false,
        "future_option": [1, 2]
    },
    "published": {
        "guidelines": ["CLAUDE.md"],
        "skills": {
            ".claude/skills": ["kept-skill"]
        },
        "rules": []
    }
}
JSON;

    $this->fixture->write('afterburner.json', $contents);
    $this->fixture->guideline('app.md', "Application guidance.\n");
    $this->fixture->skill('kept-skill');

    $this->artisan('afterburner:install', ['--agents' => 'claude', '--base' => 'none', '--no-interaction' => true])
        ->assertSuccessful();

    $expected = json_decode($contents, true);
    $expected['agents'] = ['claude'];
    $expected['base'] = 'none';

    expect($this->fixture->configuration())->toBe($expected)
        ->and($this->fixture->read('afterburner.json'))->toContain('"extra": {}');
});
