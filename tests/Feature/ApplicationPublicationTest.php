<?php

declare(strict_types=1);

use Shipwell\Afterburner\Publication\ManagedBlock;
use Tests\Support\CommandResult;

function managedBlock(string $document): string
{
    preg_match('/<afterburner-guidelines>\n(.*?)\n\n<\/afterburner-guidelines>/s', $document, $matches);

    return $matches[1] ?? '';
}

function install(array $parameters = []): CommandResult
{
    return runCommand('afterburner:install', ['--base' => 'none', ...$parameters]);
}

beforeEach(function () {
    $this->fixture
        ->guideline('architecture.md', "# Architecture\n\nUse actions for writes.\n")
        ->guideline('testing/style.md', "Write feature tests first.\n")
        ->skill('deploying', 'Deploy the application.', "# Deploying\n\nSee [the checklist](references/checklist.md).\n", [
            'references/checklist.md' => "- [ ] Run migrations\n",
            'scripts/deploy.sh' => "#!/bin/sh\necho deploy\n",
        ]);

    chmod($this->fixture->path('.ai/skills/deploying/scripts/deploy.sh'), 0755);
});

it('publishes guidelines and complete skill folders for Claude Code', function () {
    expect(install(['--agents' => 'claude'])->successful())->toBeTrue();

    $block = managedBlock($this->fixture->read('CLAUDE.md'));

    expect($block)
        ->toContain("=== .ai/architecture rules ===\n\n# Architecture\n\nUse actions for writes.")
        ->toContain("=== .ai/testing/style rules ===\n\nWrite feature tests first.")
        ->and(strpos($block, '.ai/architecture'))->toBeLessThan(strpos($block, '.ai/testing/style'))
        ->and($this->fixture->read('.claude/skills/deploying/SKILL.md'))->toBe($this->fixture->read('.ai/skills/deploying/SKILL.md'))
        ->and($this->fixture->read('.claude/skills/deploying/references/checklist.md'))->toBe("- [ ] Run migrations\n")
        ->and(is_executable($this->fixture->path('.claude/skills/deploying/scripts/deploy.sh')))->toBeTrue()
        ->and($this->fixture->exists('AGENTS.md'))->toBeFalse()
        ->and($this->fixture->exists('.agents'))->toBeFalse()
        ->and($this->fixture->configuration()['published'])->toBe([
            'guidelines' => ['CLAUDE.md'],
            'skills' => ['.claude/skills' => ['deploying']],
            'rules' => [],
        ]);
});

it('publishes to the shared agent destinations', function (string $agent) {
    expect(install(['--agents' => $agent])->successful())->toBeTrue()
        ->and(managedBlock($this->fixture->read('AGENTS.md')))->toContain('Use actions for writes.')
        ->and($this->fixture->exists('.agents/skills/deploying/references/checklist.md'))->toBeTrue()
        ->and($this->fixture->exists('CLAUDE.md'))->toBeFalse()
        ->and($this->fixture->exists('.claude'))->toBeFalse();
})->with(['codex', 'cursor', 'pi', 'opencode']);

it('writes equivalent guidance to both documents and shared roots once for all agents', function () {
    expect(install(['--agents' => 'claude,codex,cursor,pi,opencode'])->successful())->toBeTrue();

    expect(managedBlock($this->fixture->read('CLAUDE.md')))->toBe(managedBlock($this->fixture->read('AGENTS.md')))
        ->and(substr_count($this->fixture->read('AGENTS.md'), ManagedBlock::OPEN))->toBe(1)
        ->and($this->fixture->exists('.claude/skills/deploying/SKILL.md'))->toBeTrue()
        ->and($this->fixture->exists('.agents/skills/deploying/SKILL.md'))->toBeTrue()
        ->and($this->fixture->configuration()['published'])->toBe([
            'guidelines' => ['CLAUDE.md', 'AGENTS.md'],
            'skills' => ['.agents/skills' => ['deploying'], '.claude/skills' => ['deploying']],
            'rules' => [],
        ]);
});

it('publishes a skill under its frontmatter name rather than its folder name', function () {
    $this->fixture->skill('release-notes', 'Write release notes.', directory: 'notes-folder');

    install(['--agents' => 'claude']);

    expect($this->fixture->exists('.claude/skills/release-notes/SKILL.md'))->toBeTrue()
        ->and($this->fixture->exists('.claude/skills/notes-folder'))->toBeFalse();
});

it('does not duplicate output when an unchanged publication repeats', function () {
    install(['--agents' => 'claude,codex']);
    $first = $this->fixture->snapshot();

    expect(runCommand('afterburner:update')->successful())->toBeTrue()
        ->and($this->fixture->snapshot())->toBe($first);

    expect(install()->successful())->toBeTrue()
        ->and($this->fixture->snapshot())->toBe($first)
        ->and(substr_count($this->fixture->read('CLAUDE.md'), '=== .ai/architecture rules ==='))->toBe(1);
});

it('preserves manual text around the managed block', function () {
    $this->fixture->write('CLAUDE.md', "# Team notes\n\nAlways ask before deploying.\n");

    install(['--agents' => 'claude']);

    $document = $this->fixture->read('CLAUDE.md');

    expect($document)->toStartWith("# Team notes\n\nAlways ask before deploying.\n\n<afterburner-guidelines>\n");

    $this->fixture->write('CLAUDE.md', $document."\n## After\n\nManual text after the block.\n");
    $this->fixture->guideline('architecture.md', "# Architecture\n\nUse services for writes.\n");

    runCommand('afterburner:update');

    expect($this->fixture->read('CLAUDE.md'))
        ->toStartWith("# Team notes\n\nAlways ask before deploying.\n\n<afterburner-guidelines>\n")
        ->toContain('Use services for writes.')
        ->not->toContain('Use actions for writes.')
        ->toEndWith("</afterburner-guidelines>\n\n## After\n\nManual text after the block.\n");
});

it('updates and removes the managed block in a document converted to CRLF', function () {
    $this->fixture->write('CLAUDE.md', "# Team notes\n\nAlways ask before deploying.\n");
    install(['--agents' => 'claude,codex']);

    $this->fixture->write('CLAUDE.md', str_replace("\n", "\r\n", $this->fixture->read('CLAUDE.md')));
    $this->fixture->guideline('architecture.md', "# Architecture\n\nUse services for writes.\n");

    expect(runCommand('afterburner:update')->successful())->toBeTrue();

    $document = $this->fixture->read('CLAUDE.md');

    expect(substr_count($document, '<afterburner-guidelines>'))->toBe(1)
        ->and($document)->toStartWith("# Team notes\r\n\r\nAlways ask before deploying.\r\n\r\n<afterburner-guidelines>\r\n")
        ->toContain('Use services for writes.')
        ->not->toContain('Use actions for writes.')
        ->and(preg_match('/(?<!\r)\n/', $document))->toBe(0);

    $snapshot = $this->fixture->snapshot();

    expect(runCommand('afterburner:update')->successful())->toBeTrue()
        ->and($this->fixture->snapshot())->toBe($snapshot);

    expect(runCommand('afterburner:install', ['--agents' => 'codex'])->successful())->toBeTrue()
        ->and($this->fixture->read('CLAUDE.md'))->toBe("# Team notes\r\n\r\nAlways ask before deploying.\r\n");
});

it('updates changed sources and removes supporting files that no longer exist', function () {
    install(['--agents' => 'claude']);

    $this->fixture->delete('.ai/skills/deploying/references/checklist.md');
    $this->fixture->write('.ai/skills/deploying/references/rollback.md', "Roll back.\n");
    $this->fixture->guideline('testing/style.md', "Write browser tests first.\n");

    runCommand('afterburner:update');

    expect($this->fixture->exists('.claude/skills/deploying/references/checklist.md'))->toBeFalse()
        ->and($this->fixture->read('.claude/skills/deploying/references/rollback.md'))->toBe("Roll back.\n")
        ->and($this->fixture->read('CLAUDE.md'))->toContain('Write browser tests first.')->not->toContain('Write feature tests first.');
});

it('removes stale managed output when sources are removed', function () {
    $this->fixture->skill('second', 'A second skill.');
    install(['--agents' => 'claude']);

    $this->fixture->delete('.ai/skills/second');

    runCommand('afterburner:update');

    expect($this->fixture->exists('.claude/skills/second'))->toBeFalse()
        ->and($this->fixture->exists('.claude/skills/deploying'))->toBeTrue()
        ->and($this->fixture->configuration()['published']['skills'])->toBe(['.claude/skills' => ['deploying']]);

    $this->fixture->delete('.ai');

    runCommand('afterburner:update');

    expect($this->fixture->exists('CLAUDE.md'))->toBeFalse()
        ->and($this->fixture->exists('.claude'))->toBeFalse()
        ->and($this->fixture->configuration()['published'])->toBe(['guidelines' => [], 'skills' => [], 'rules' => []]);
});

it('keeps untracked sibling skills through refresh and cleanup', function () {
    $this->fixture->skillAt('.claude/skills/hand-written', 'hand-written', 'Kept by the team.');

    install(['--agents' => 'claude']);
    $this->fixture->delete('.ai/skills/deploying');
    runCommand('afterburner:update');
    runCommand('afterburner:install', ['--agents' => 'codex']);

    expect($this->fixture->exists('.claude/skills/hand-written/SKILL.md'))->toBeTrue()
        ->and($this->fixture->exists('.claude/skills/deploying'))->toBeFalse();
});

it('refuses to overwrite an untracked skill with the same name', function () {
    $this->fixture->skillAt('.claude/skills/deploying', 'deploying', 'Someone else\'s skill.');
    $before = $this->fixture->snapshot();

    $result = install(['--agents' => 'claude']);

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('.claude/skills/deploying', 'did not publish')
        ->and($this->fixture->snapshot())->toBe($before);
});

it('refuses to guess when its own delimiters are malformed', function (string $document) {
    $this->fixture->write('CLAUDE.md', $document);
    $before = $this->fixture->snapshot();

    $result = install(['--agents' => 'claude']);

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('CLAUDE.md', 'delimiters are malformed')
        ->and($this->fixture->snapshot())->toBe($before);
})->with([
    'missing close' => ["Notes\n<afterburner-guidelines>\nold\n"],
    'missing open' => ["Notes\nold\n</afterburner-guidelines>\n"],
    'reversed' => ["</afterburner-guidelines>\nmiddle\n<afterburner-guidelines>\n"],
    'duplicated' => ["<afterburner-guidelines>\na\n</afterburner-guidelines>\n<afterburner-guidelines>\nb\n</afterburner-guidelines>\n"],
]);

it('ignores delimiter text that is not on its own line', function () {
    $this->fixture->write('CLAUDE.md', "Afterburner manages the `<afterburner-guidelines>` section below.\n");

    expect(install(['--agents' => 'claude'])->successful())->toBeTrue()
        ->and($this->fixture->read('CLAUDE.md'))->toStartWith("Afterburner manages the `<afterburner-guidelines>` section below.\n\n<afterburner-guidelines>\n");
});

it('fails on invalid selected sources without changing previous output', function (Closure $break, string $message) {
    install(['--agents' => 'claude,codex']);
    $before = $this->fixture->snapshot();
    $configuration = $this->fixture->read('afterburner.json');

    $break($this->fixture);
    $broken = $this->fixture->snapshot();

    $result = runCommand('afterburner:update');

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain($message, 'Nothing was published')
        ->and($this->fixture->snapshot())->toBe($broken)
        ->and($this->fixture->read('afterburner.json'))->toBe($configuration)
        ->and(array_diff_key($this->fixture->snapshot(), $before))->toBe(array_diff_key($broken, $before));
})->with([
    'missing description' => [
        fn ($fixture) => $fixture->write('.ai/skills/broken/SKILL.md', "---\nname: broken\n---\n# Broken\n"),
        '.ai/skills/broken/SKILL.md: the skill frontmatter must define a nonempty `description`',
    ],
    'missing name' => [
        fn ($fixture) => $fixture->write('.ai/skills/broken/SKILL.md', "---\ndescription: No name.\n---\n"),
        'nonempty `name`',
    ],
    'no frontmatter' => [
        fn ($fixture) => $fixture->write('.ai/skills/broken/SKILL.md', "# Just text\n"),
        'has no YAML frontmatter',
    ],
    'unclosed frontmatter' => [
        fn ($fixture) => $fixture->write('.ai/skills/broken/SKILL.md', "---\nname: broken\ndescription: x\n"),
        'no closing `---` delimiter',
    ],
    'invalid yaml' => [
        fn ($fixture) => $fixture->write('.ai/skills/broken/SKILL.md', "---\nname: [broken\ndescription: x\n---\n"),
        'not valid YAML',
    ],
    'duplicate identity' => [
        fn ($fixture) => $fixture->skill('deploying', 'Another deploying skill.', directory: 'deploy-copy'),
        'application skill names must be unique',
    ],
    'unsafe name' => [
        fn ($fixture) => $fixture->write('.ai/skills/escape/SKILL.md', "---\nname: ../escape\ndescription: x\n---\n"),
        'cannot be used as a directory name',
    ],
    'unreadable guideline' => [
        function ($fixture) {
            $fixture->guideline('secret.md', 'hidden');
            chmod($fixture->path('.ai/guidelines/secret.md'), 0000);
        },
        '.ai/guidelines/secret.md: the file is not readable',
    ],
]);

it('keeps shared roots while another selected agent needs them', function () {
    install(['--agents' => 'codex,cursor']);

    expect(runCommand('afterburner:install', ['--agents' => 'cursor'])->successful())->toBeTrue()
        ->and($this->fixture->exists('.agents/skills/deploying/SKILL.md'))->toBeTrue()
        ->and(managedBlock($this->fixture->read('AGENTS.md')))->toContain('Use actions for writes.');
});

it('removes managed output when the last agent needing it is deselected', function () {
    $this->fixture->write('AGENTS.md', "# Shared notes\n");
    install(['--agents' => 'claude,codex']);

    expect(runCommand('afterburner:install', ['--agents' => 'claude'])->successful())->toBeTrue()
        ->and($this->fixture->read('AGENTS.md'))->toBe("# Shared notes\n")
        ->and($this->fixture->exists('.agents'))->toBeFalse()
        ->and($this->fixture->exists('.claude/skills/deploying'))->toBeTrue()
        ->and($this->fixture->configuration()['published'])->toBe([
            'guidelines' => ['CLAUDE.md'],
            'skills' => ['.claude/skills' => ['deploying']],
            'rules' => [],
        ]);

    expect(runCommand('afterburner:install', ['--agents' => 'codex'])->successful())->toBeTrue()
        ->and($this->fixture->exists('CLAUDE.md'))->toBeFalse()
        ->and($this->fixture->exists('.claude'))->toBeFalse()
        ->and($this->fixture->read('AGENTS.md'))->toStartWith("# Shared notes\n\n<afterburner-guidelines>");
});

it('removes all managed output for an explicit empty agent selection', function () {
    $this->fixture->write('CLAUDE.md', "Manual.\n");
    install(['--agents' => 'claude,opencode']);

    $configuration = $this->fixture->configuration();
    $configuration['agents'] = [];
    $this->fixture->configure($configuration);

    expect(runCommand('afterburner:update')->successful())->toBeTrue()
        ->and($this->fixture->read('CLAUDE.md'))->toBe("Manual.\n")
        ->and($this->fixture->exists('AGENTS.md'))->toBeFalse()
        ->and($this->fixture->exists('.claude'))->toBeFalse()
        ->and($this->fixture->exists('.agents'))->toBeFalse();
});

it('requires saved agents before updating', function () {
    $result = runCommand('afterburner:update');

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('No agents are saved')
        ->and($this->fixture->exists('afterburner.json'))->toBeFalse();
});

it('reports filesystem failures and records the output it owns', function () {
    $this->fixture->write('.claude', 'not a directory');

    $result = install(['--agents' => 'claude']);

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('Unable to create the directory .claude/skills', 'Publication stopped')
        ->and($this->fixture->configuration())->not->toHaveKey('agents')
        ->and($this->fixture->configuration()['published']['skills'])->toBe(['.claude/skills' => ['deploying']])
        ->and($this->fixture->exists('CLAUDE.md'))->toBeFalse();
});
