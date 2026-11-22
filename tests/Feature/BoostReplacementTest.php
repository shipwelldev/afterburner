<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Tests\Support\BoostApplication;
use Tests\Support\CommandResult;
use Tests\Support\FixtureApplication;
use Tests\Support\IsolatedEnvironment;

function replace(array $parameters = []): CommandResult
{
    return runCommand('afterburner:install', ['--replace-boost' => true, ...$parameters]);
}

function fakeInstalledBoost(FixtureApplication $fixture, string $section = 'require-dev'): void
{
    $fixture->composerPackage('laravel/boost', '2.10.4', dev: $section === 'require-dev');
    $fixture->writeJson('vendor/composer/installed.json', ['packages' => [['name' => 'laravel/boost', 'version' => 'v2.10.4']]]);
}

function useFakeComposer(IsolatedEnvironment $environment, string $mode): string
{
    $log = $environment->home().'/composer.log';
    $environment->set('AFTERBURNER_COMPOSER', dirname(__DIR__).'/Fixtures/bin/fake-composer.php');
    $environment->set('FAKE_COMPOSER_MODE', $mode);
    $environment->set('FAKE_COMPOSER_LOG', $log);

    return $log;
}

beforeEach(function () {
    BoostApplication::seed($this->fixture, $this->environment->home());
});

it('replaces an already removed Boost installation using its captured choices', function () {
    $result = replace();

    expect($result->successful())->toBeTrue($result->output)
        ->and($result->output)->toContain('not installed, so no Composer change is needed', 'Laravel Boost has been replaced by Afterburner.')
        ->and(Arr::except($this->fixture->configuration(), 'published'))->toEqual([
            'agents' => ['claude', 'cursor'],
            'base' => 'boost',
            'integrations' => ['sail' => false, 'cloud' => true],
        ]);

    $claude = $this->fixture->read('CLAUDE.md');

    expect($claude)
        ->toStartWith(BoostApplication::MANUAL_CLAUDE."\n<afterburner-guidelines>\n")
        ->toEndWith("</afterburner-guidelines>\n".BoostApplication::MANUAL_AFTER)
        ->not->toContain('laravel-boost-guidelines')
        ->not->toContain('Old Boost guidance.')
        ->toContain('=== foundation rules ===')
        ->and($this->fixture->read('AGENTS.md'))->toStartWith('<afterburner-guidelines>')->not->toContain('laravel-boost-guidelines')
        ->and($this->fixture->exists('afterburner.json'))->toBeTrue()
        ->and($this->fixture->exists('storage/framework/afterburner-replacement.json'))->toBeFalse();
});

it('removes the separator Boost adds before an appended block', function () {
    $this->fixture->write('AGENTS.md', "# Shared notes\n\n===\n\n<laravel-boost-guidelines>\nOld.\n</laravel-boost-guidelines>\n");

    replace();

    expect($this->fixture->read('AGENTS.md'))->toStartWith("# Shared notes\n\n<afterburner-guidelines>\n")->not->toContain('===\n\n<afterburner');
});

it('replaces a Boost block and its separator in a CRLF document', function () {
    $this->fixture->write('AGENTS.md', "# Shared notes\r\n\r\n===\r\n\r\n<laravel-boost-guidelines>\r\nOld.\r\n</laravel-boost-guidelines>\r\n");

    expect(replace()->successful())->toBeTrue();

    $document = $this->fixture->read('AGENTS.md');

    expect($document)->toStartWith("# Shared notes\r\n\r\n<afterburner-guidelines>\r\n")
        ->not->toContain('laravel-boost-guidelines')
        ->not->toContain("Old.\r\n")
        ->and(substr_count($document, '<afterburner-guidelines>'))->toBe(1);
});

it('adopts Boost-tracked skills, removes them from old roots, and keeps everything untracked', function () {
    replace();

    expect($this->fixture->read('.claude/skills/laravel-best-practices/SKILL.md'))->not->toContain('Old Boost copy')
        ->and($this->fixture->exists('.claude/skills/laravel-best-practices/rules/old-only.md'))->toBeFalse()
        ->and($this->fixture->read('.claude/skills/infer-conventions/SKILL.md'))->not->toContain('Old Boost copy')->toContain('name: infer-conventions')
        ->and(is_link($this->fixture->path('.claude/skills/team-custom')))->toBeFalse()
        ->and($this->fixture->read('.claude/skills/team-custom/SKILL.md'))->toBe($this->fixture->read('.ai/skills/team-custom/SKILL.md'))
        ->and($this->fixture->exists('.claude/skills/hand-made/SKILL.md'))->toBeTrue()
        ->and($this->fixture->exists('.cursor/skills/laravel-best-practices'))->toBeFalse()
        ->and($this->fixture->exists('.cursor/skills/cursor-only/SKILL.md'))->toBeTrue()
        ->and($this->fixture->exists('.agents/skills/laravel-best-practices/SKILL.md'))->toBeTrue()
        ->and($this->fixture->exists('.ai/skills/team-custom/SKILL.md'))->toBeTrue();
});

it('adopts Boost-generated rules and keeps user rules', function () {
    replace();

    expect($this->fixture->exists('.ai/rules/boost/models.md'))->toBeFalse()
        ->and($this->fixture->read('.ai/rules/team.md'))->toBe(BoostApplication::USER_RULE)
        ->and($this->fixture->read('.ai/rules/index.md'))->toContain('.ai/rules/team.md')->not->toContain('Old Boost index');
});

it('removes only the recognized project-local Boost MCP entries', function () {
    $result = replace();

    expect(json_decode($this->fixture->read('.mcp.json'), true))->toBe(['mcpServers' => ['github' => ['command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-github']]]])
        ->and($this->fixture->read('opencode.jsonc'))->toBe(<<<'JSONC'
{
  // Team OpenCode settings
  "$schema": "https://opencode.ai/config.json",
  "mcp": {
    /* our docs server */
    "docs": {
      "type": "local",
      "command": ["node", "docs-server.js"],
    }
  },
  "theme": "dark", // keep this
}

JSONC)
        ->and($this->fixture->read('.codex/config.toml'))->toBe(<<<'TOML'
# Codex project settings
model = "gpt-5"

# Our own server
[mcp_servers.docs]
command = "node"
args = ["docs-server.js"]

TOML)
        ->and($this->fixture->exists('.cursor/mcp.json'))->toBeFalse()
        ->and($this->fixture->exists('.cursor/skills/cursor-only'))->toBeTrue()
        ->and(file_get_contents($this->environment->home().'/.cursor/mcp.json'))->toBe(BoostApplication::GLOBAL_CURSOR)
        ->and($result->output)->toContain('Afterburner does not edit global agent settings');
});

it('recognizes PHP, Sail, and WSL launches and keeps anything ambiguous', function (array $entry, bool $removed) {
    $this->fixture->writeJson('.mcp.json', ['mcpServers' => ['laravel-boost' => $entry, 'other' => ['command' => 'node']]]);

    $result = replace();

    expect(array_key_exists('laravel-boost', json_decode($this->fixture->read('.mcp.json'), true)['mcpServers']))->toBe(! $removed);

    if (! $removed) {
        expect($result->output)->toContain('.mcp.json: the laravel-boost entry does not launch `artisan boost:mcp`, so it was kept');
    }
})->with([
    'php' => [['command' => 'php', 'args' => ['artisan', 'boost:mcp']], true],
    'absolute php' => [['command' => '/usr/local/bin/php8.4', 'args' => ['/srv/app/artisan', 'boost:mcp'], 'env' => []], true],
    'herd php' => [['command' => '/Users/me/Library/Application Support/Herd/bin/php', 'args' => ['artisan', 'boost:mcp']], true],
    'sail' => [['command' => 'vendor/bin/sail', 'args' => ['artisan', 'boost:mcp']], true],
    'wsl' => [['command' => 'wsl.exe', 'args' => ['/usr/bin/php', '/home/me/app/artisan', 'boost:mcp']], true],
    'custom server under the boost key' => [['command' => 'node', 'args' => ['my-boost.js']], false],
    'http server under the boost key' => [['type' => 'http', 'url' => 'https://example.test/mcp'], false],
    'different artisan command' => [['command' => 'php', 'args' => ['artisan', 'my:mcp']], false],
]);

it('reports other servers that run boost:mcp without removing them', function () {
    $this->fixture->writeJson('.mcp.json', ['mcpServers' => ['my-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']]]]);

    $result = replace();

    expect($this->fixture->json('.mcp.json'))->toBe(['mcpServers' => ['my-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']]]])
        ->and($result->output)->toContain('a server other than laravel-boost runs boost:mcp');
});

it('leaves unsupported syntax for manual cleanup', function () {
    $this->fixture->write('.codex/config.toml', "mcp_servers = { laravel-boost = { command = \"php\", args = [\"artisan\", \"boost:mcp\"] } }\n");

    $result = replace();

    expect($this->fixture->read('.codex/config.toml'))->toContain('laravel-boost')
        ->and($result->output)->toContain('.codex/config.toml: the laravel-boost server is not written as a [mcp_servers.laravel-boost] table');
});

it('reports unsupported agents and leaves their output alone', function () {
    $result = replace();

    expect($result->output)->toContain('Boost was configured for copilot, which Afterburner does not support')
        ->and($this->fixture->read('.github/copilot-instructions.md'))->toContain('<laravel-boost-guidelines>');
});

it('lets explicit Afterburner choices win over imported ones', function (array $existing, array $parameters, array $expected) {
    if ($existing !== []) {
        $this->fixture->configure($existing);
    }

    expect(replace($parameters)->successful())->toBeTrue()
        ->and(Arr::only($this->fixture->configuration(), ['agents', 'base', 'integrations']))->toEqual($expected);
})->with([
    'saved choices' => [['agents' => ['codex'], 'base' => 'core', 'integrations' => ['cloud' => false]], [], ['agents' => ['codex'], 'base' => 'core', 'integrations' => ['cloud' => false, 'sail' => false]]],
    'command line' => [[], ['--agents' => 'opencode', '--base' => 'afterburner'], ['agents' => ['opencode'], 'base' => 'afterburner', 'integrations' => ['sail' => false, 'cloud' => true]]],
]);

it('imports applicable Boost configuration values', function () {
    $this->fixture->write('config/boost.php', "<?php\n\nreturn ['purpose' => 'Fleet maintenance tracking.', 'enforce_tests' => true, 'rules' => ['scoped_guidelines' => true], 'agents' => [\n    'claude_code' => ['skills_path' => '.ai-skills/claude', 'guidelines_path' => 'docs/CLAUDE.md', 'mcp_config_path' => 'config/mcp/claude.json'],\n    'cursor' => ['mcp_config_path' => '/etc/cursor/mcp.json'],\n]];\n");
    config()->set('boost', require $this->fixture->path('config/boost.php'));

    // Boost wrote Claude's skills to the configured path, not .claude/skills.
    $this->fixture->delete('.claude/skills/infer-conventions')->delete('.claude/skills/laravel-best-practices')->delete('.claude/skills/team-custom');

    $this->fixture
        ->write('docs/CLAUDE.md', "Docs intro.\n\n<laravel-boost-guidelines>\nOld.\n</laravel-boost-guidelines>\n")
        ->skillAt('.ai-skills/claude/laravel-best-practices', 'laravel-best-practices', 'Boost copy in a custom path.')
        ->writeJson('config/mcp/claude.json', ['mcpServers' => ['laravel-boost' => ['command' => 'php', 'args' => ['artisan', 'boost:mcp']]]]);

    $result = replace();

    expect($result->successful())->toBeTrue($result->output)
        ->and($this->fixture->configuration())->toMatchArray(['purpose' => 'Fleet maintenance tracking.', 'enforce_tests' => true, 'rules' => ['scoped_guidelines' => true]])
        ->and($this->fixture->read('docs/CLAUDE.md'))->toBe("Docs intro.\n")
        ->and($this->fixture->exists('.ai-skills/claude/laravel-best-practices'))->toBeFalse()
        ->and($this->fixture->exists('config/mcp/claude.json'))->toBeFalse()
        ->and($result->output)->toContain('/etc/cursor/mcp.json, which is outside this application');
});

it('fails before changing anything when agents cannot be resolved', function () {
    $this->fixture->writeJson('boost.json', ['agents' => ['copilot', 'junie'], 'skills' => []]);
    $before = $this->fixture->snapshot();

    $result = replace();

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('No agents could be resolved', 'copilot, junie', '--agents')
        ->and($this->fixture->snapshot())->toBe($before);
});

it('validates the selection before changing anything', function (Closure $break, string $message) {
    fakeInstalledBoost($this->fixture);
    $log = useFakeComposer($this->environment, 'remove');
    $break($this->fixture);
    $before = $this->fixture->snapshot();

    $result = replace();

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain($message, 'stopped before changing anything')
        ->and($this->fixture->snapshot())->toBe($before)
        ->and(file_exists($log))->toBeFalse();
})->with([
    'invalid selected asset' => [fn ($fixture) => $fixture->guideline('broken.blade.php', "{{ \$assist->missing() }}\n"), '.ai/guidelines/broken.blade.php'],
    'malformed Boost block' => [fn ($fixture) => $fixture->write('AGENTS.md', "<laravel-boost-guidelines>\nunterminated\n"), 'AGENTS.md: the Laravel Boost guideline delimiters are malformed'],
    'malformed boost.json' => [fn ($fixture) => $fixture->write('boost.json', '{"agents": ['), 'boost.json: it is not a valid JSON object'],
]);

it('removes the development requirement with Composer and stops when Composer fails', function (string $mode, string $message) {
    fakeInstalledBoost($this->fixture);
    $log = useFakeComposer($this->environment, $mode);
    $before = Arr::except($this->fixture->snapshot(), ['composer.json']);

    $result = replace();

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain($message, 'did not publish anything or edit MCP configuration')
        ->and(json_decode(trim(file_get_contents($log)), true))->toBe(['remove', 'laravel/boost', '--no-interaction', '--no-progress', '--dev'])
        ->and(Arr::except($this->fixture->snapshot(), ['composer.json']))->toBe($before)
        ->and($this->fixture->exists('storage/framework/afterburner-replacement.json'))->toBeFalse();
})->with([
    'composer error' => ['fail', 'Composer did not remove laravel/boost (exit code 2); it is still installed.'],
    'required by another package' => ['blocked', 'composer why laravel/boost'],
]);

it('removes a production requirement without --dev', function () {
    fakeInstalledBoost($this->fixture, 'require');
    $log = useFakeComposer($this->environment, 'fail');

    replace();

    expect(json_decode(trim(file_get_contents($log)), true))->toBe(['remove', 'laravel/boost', '--no-interaction', '--no-progress']);
});

it('uses the recorded choices for an update while the replacement is pending', function () {
    $this->fixture->writeJson('storage/framework/afterburner-replacement.json', [
        'version' => 1,
        'started_at' => '2026-11-21 10:00:00 UTC',
        'agents' => ['codex'],
        'base' => 'boost',
        'preferences' => ['sail' => null, 'cloud' => null, 'enforce_tests' => null, 'scoped_guidelines' => null],
        'adoption' => ['documents' => ['CLAUDE.md', 'AGENTS.md'], 'skills' => ['.claude/skills' => ['laravel-best-practices']], 'rules' => []],
        'mcp_locations' => [['.mcp.json', 'json', 'mcpServers']],
        'notes' => [],
    ]);

    $result = runCommand('boost:update', ['--ansi' => true]);

    expect($result->successful())->toBeTrue($result->output)
        ->and($result->output)->toContain('replacement is in progress')->not->toContain('has been replaced')
        ->and($this->fixture->read('AGENTS.md'))->toContain('<afterburner-guidelines>')
        ->and($this->fixture->read('CLAUDE.md'))->not->toContain('laravel-boost-guidelines')->not->toContain('afterburner-guidelines')
        ->and($this->fixture->exists('storage/framework/afterburner-replacement.json'))->toBeTrue()
        ->and($this->fixture->read('.mcp.json'))->toContain('laravel-boost');
});

it('can repeat the replacement after a failed finalization', function () {
    fakeInstalledBoost($this->fixture);
    useFakeComposer($this->environment, 'remove');

    // Boost is removed, but this fixture has no artisan file for the fresh finalization process.
    $removed = replace();

    expect($removed->exitCode)->toBe(1)
        ->and($removed->output)->toContain('Laravel Boost was removed. Run `php artisan afterburner:install --replace-boost` to finish')
        ->and($this->fixture->exists('storage/framework/afterburner-replacement.json'))->toBeTrue()
        ->and($this->fixture->read('CLAUDE.md'))->toContain('<laravel-boost-guidelines>')
        ->and($this->fixture->json('composer.json')['require-dev'] ?? [])->not->toHaveKey('laravel/boost');

    $this->fixture->guideline('broken.blade.php', "{{ \$assist->missing() }}\n");

    $failed = replace();

    expect($failed->exitCode)->toBe(1)
        ->and($failed->output)->toContain('Finishing the Laravel Boost replacement', 'Laravel Boost has been removed, but publishing failed')
        ->and($this->fixture->exists('storage/framework/afterburner-replacement.json'))->toBeTrue()
        ->and($this->fixture->read('CLAUDE.md'))->toContain('<laravel-boost-guidelines>');

    $this->fixture->guideline('broken.blade.php', "Fixed.\n");

    $repeated = replace();

    expect($repeated->successful())->toBeTrue($repeated->output)
        ->and($repeated->output)->toContain('Laravel Boost has been replaced by Afterburner.')
        ->and($this->fixture->exists('storage/framework/afterburner-replacement.json'))->toBeFalse()
        ->and($this->fixture->read('CLAUDE.md'))->not->toContain('laravel-boost-guidelines')
        ->and($this->fixture->read('.mcp.json'))->not->toContain('laravel-boost')
        ->and(Arr::only($this->fixture->configuration(), ['agents', 'base']))->toBe(['agents' => ['claude', 'cursor'], 'base' => 'boost']);
});

it('offers replacement interactively and defaults to no', function () {
    $this->fixture->configure(['agents' => ['claude'], 'base' => 'none']);

    // Declining runs a normal install, which refuses to claim Boost's untracked skill output.
    $this->artisan('afterburner:install')
        ->expectsConfirmation('Laravel Boost is present. Replace it with Afterburner now?', 'no')
        ->expectsOutputToContain('.claude/skills/team-custom')
        ->assertFailed();

    expect($this->fixture->read('CLAUDE.md'))->toContain('<laravel-boost-guidelines>')
        ->and($this->fixture->read('.mcp.json'))->toContain('laravel-boost')
        ->and($this->fixture->exists('storage/framework/afterburner-replacement.json'))->toBeFalse();

    $this->artisan('afterburner:install')
        ->expectsConfirmation('Laravel Boost is present. Replace it with Afterburner now?', 'yes')
        ->assertSuccessful();

    expect($this->fixture->read('CLAUDE.md'))->not->toContain('<laravel-boost-guidelines>')
        ->and($this->fixture->read('.mcp.json'))->not->toContain('laravel-boost');
});
