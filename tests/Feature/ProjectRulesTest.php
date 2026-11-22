<?php

declare(strict_types=1);
use Tests\Support\CommandResult;

function rule(array $paths, string $body = "# Rule\n"): string
{
    return "---\npaths:\n".implode('', array_map(fn ($path) => "  - '{$path}'\n", $paths))."---\n\n".$body;
}

/**
 * @return array<int, array{0: string, 1: string}>
 */
function indexRows(string $index): array
{
    preg_match_all('/^\| (?!Applies to|---)(.+?) \| (.+?) \|$/m', $index, $matches, PREG_SET_ORDER);

    return array_map(fn ($match) => [$match[1], $match[2]], $matches);
}

function publishRules(bool $extract, array $extra = []): CommandResult
{
    test()->fixture->reconfigure(array_merge(['agents' => ['claude'], 'base' => 'none', 'rules' => ['scoped_guidelines' => $extract]], $extra));

    return runCommand('afterburner:update');
}

describe('index command', function () {
    it('succeeds without creating anything when there is no rules directory', function (string $command) {
        $result = runCommand($command);

        expect($result->successful())->toBeTrue()
            ->and($result->output)->toContain('No project rules found in .ai/rules.')
            ->and($this->fixture->exists('.ai'))->toBeFalse();
    })->with(['afterburner:index-rules', 'boost:index-rules']);

    it('indexes an empty rules directory without inventing rules', function () {
        mkdir($this->fixture->path('.ai/rules'), 0755, true);

        expect(runCommand('afterburner:index-rules')->successful())->toBeTrue()
            ->and(glob($this->fixture->path('.ai/rules/*')))->toBe([$this->fixture->path('.ai/rules/index.md')])
            ->and($this->fixture->read('.ai/rules/index.md'))->toContain('No rules recorded yet.');
    });

    it('maps every scope to its rule file and asks agents to read all matching rules', function () {
        $this->fixture
            ->write('.ai/rules/models.md', rule(['app/Models/**', 'database/migrations/**'], "# Models\n\n## Keys\nUse UUID keys.\n"))
            ->write('.ai/rules/eloquent.md', rule(['app/Models/**'], "# Eloquent\n"))
            ->write('.ai/rules/boost/http.md', rule(['app/Http/**']))
            ->write('.ai/rules/http.md', rule(['app/Http/**'], "# HTTP | API\n"));

        expect(runCommand('afterburner:index-rules')->successful())->toBeTrue();

        $index = $this->fixture->read('.ai/rules/index.md');

        expect($index)
            ->toContain('find every row whose globs match')
            ->toContain('A file can match several rows; follow all of the matching rules.')
            ->and(indexRows($index))->toBe([
                ['app/Http/**', '.ai/rules/boost/http.md'],
                ['app/Models/**', '.ai/rules/eloquent.md'],
                ['app/Http/**', '.ai/rules/http.md'],
                ['app/Models/**, database/migrations/**', '.ai/rules/models.md'],
            ]);
    });

    it('produces the same index regardless of file creation order or repetition', function () {
        $this->fixture->write('.ai/rules/zeta.md', rule(['z/**']))->write('.ai/rules/alpha.md', rule(['a/**']));
        runCommand('afterburner:index-rules');
        $first = $this->fixture->read('.ai/rules/index.md');

        $this->fixture->delete('.ai/rules');
        $this->fixture->write('.ai/rules/alpha.md', rule(['a/**']))->write('.ai/rules/zeta.md', rule(['z/**']));
        runCommand('afterburner:index-rules');
        runCommand('afterburner:index-rules');

        expect($this->fixture->read('.ai/rules/index.md'))->toBe($first)
            ->and(array_column(indexRows($first), 1))->toBe(['.ai/rules/alpha.md', '.ai/rules/zeta.md']);
    });

    it('warns about rule files it cannot index', function () {
        $this->fixture
            ->write('.ai/rules/good.md', rule(['app/**']))
            ->write('.ai/rules/no-frontmatter.md', "# Notes\n")
            ->write('.ai/rules/no-paths.md', "---\ntitle: Notes\n---\n# Notes\n")
            ->write('.ai/rules/empty-paths.md', "---\npaths: []\n---\n")
            ->write('.ai/rules/broken-yaml.md', "---\npaths: [app/**\n---\n")
            ->write('.ai/rules/nested/deep.md', rule(['deep/**']));

        $result = runCommand('afterburner:index-rules');

        expect($result->successful())->toBeTrue()
            ->and($result->output)
            ->toContain('Skipped .ai/rules/no-frontmatter.md: no valid `paths` frontmatter')
            ->toContain('Skipped .ai/rules/no-paths.md: no valid `paths` frontmatter')
            ->toContain('Skipped .ai/rules/empty-paths.md: no valid `paths` frontmatter')
            ->toContain('Skipped .ai/rules/broken-yaml.md: the frontmatter could not be parsed')
            ->and(indexRows($this->fixture->read('.ai/rules/index.md')))->toBe([['app/**', '.ai/rules/good.md']]);
    });

    it('fails on unresolved merge conflicts and keeps the previous index', function (string $command) {
        $this->fixture->write('.ai/rules/models.md', rule(['app/Models/**']));
        runCommand('afterburner:index-rules');
        $index = $this->fixture->read('.ai/rules/index.md');

        $this->fixture->write('.ai/rules/conflicted.md', rule(['app/**'], "<<<<<<< HEAD\nOne\n=======\nTwo\n>>>>>>> branch\n"));
        $this->fixture->write('.ai/rules/boost/managed.md', rule(['x/**'], ">>>>>>> other\n"));

        $result = runCommand($command);

        expect($result->exitCode)->toBe(1)
            ->and($result->output)->toContain('Resolve the merge conflicts', '.ai/rules/conflicted.md', '.ai/rules/boost/managed.md')
            ->and($this->fixture->read('.ai/rules/index.md'))->toBe($index);
    })->with(['afterburner:index-rules', 'boost:index-rules']);

    it('indexes existing Boost rule files and manual additions without changing them', function () {
        $boostRule = "---\npaths:\n  - 'app/Models/**'\n---\n\n# Models\n\n## Use UUIDs\nModels use UUID primary keys.\n";
        $this->fixture->write('.ai/rules/models.md', $boostRule);

        runCommand('boost:index-rules');
        $this->fixture->write('.ai/rules/controllers.md', rule(['app/Http/Controllers/**'], "# Controllers\n\nKeep them thin.\n"));
        $rulesBefore = [$this->fixture->read('.ai/rules/models.md'), $this->fixture->read('.ai/rules/controllers.md')];

        runCommand('afterburner:index-rules');

        expect(indexRows($this->fixture->read('.ai/rules/index.md')))->toBe([
            ['app/Http/Controllers/**', '.ai/rules/controllers.md'],
            ['app/Models/**', '.ai/rules/models.md'],
        ])->and([$this->fixture->read('.ai/rules/models.md'), $this->fixture->read('.ai/rules/controllers.md')])->toBe($rulesBefore);
    });

    it('behaves identically through the compatibility entry point', function () {
        $this->fixture->write('.ai/rules/a.md', rule(['a/**']))->write('.ai/rules/b.md', "# no paths\n");

        $canonical = runCommand('afterburner:index-rules');
        $index = $this->fixture->read('.ai/rules/index.md');
        $this->fixture->delete('.ai/rules/index.md');
        $alias = runCommand('boost:index-rules');

        expect($alias->exitCode)->toBe($canonical->exitCode)
            ->and($alias->output)->toBe($canonical->output)
            ->and($this->fixture->read('.ai/rules/index.md'))->toBe($index);
    });
});

describe('scoped guideline extraction', function () {
    beforeEach(function () {
        $this->fixture->guideline('models.blade.php', <<<'BLADE'
# Models

General model guidance.

@scoped(['database/migrations/**', 'app/Models/**'])
Define casts in the casts() method.
@endscoped

@scoped(['app/Models/**', 'database/migrations/**'])
Use UUID primary keys.
@endscoped

@scoped(['app/Http/**'])
## Controllers

Keep controllers thin.
@endscoped
BLADE);
        $this->fixture->guideline('only-scoped.blade.php', "@scoped(['tests/**'])\nPrefer factories.\n@endscoped\n");
        $this->fixture->write('.ai/rules/team.md', rule(['app/**'], "# Team\n\nUser rule body.\n"));
    });

    it('keeps scoped bodies inline by default', function () {
        $userRule = $this->fixture->read('.ai/rules/team.md');

        expect(publishRules(false)->successful())->toBeTrue()
            ->and($this->fixture->read('CLAUDE.md'))
            ->toContain('Define casts in the casts() method.', 'Keep controllers thin.', 'Prefer factories.')
            ->and($this->fixture->exists('.ai/rules/boost'))->toBeFalse()
            ->and($this->fixture->exists('.ai/rules/index.md'))->toBeFalse()
            ->and($this->fixture->read('.ai/rules/team.md'))->toBe($userRule);
    });

    it('moves scoped bodies into grouped managed rules when enabled', function () {
        expect(publishRules(true)->successful())->toBeTrue();

        $guidelines = $this->fixture->read('CLAUDE.md');

        expect($guidelines)
            ->toContain('General model guidance.')
            ->not->toContain('Define casts')
            ->not->toContain('Use UUID primary keys.')
            ->not->toContain('Keep controllers thin.')
            ->not->toContain('only-scoped rules')
            ->and($this->fixture->read('.ai/rules/boost/models-migrations.md'))->toBe(
                "---\npaths:\n  - 'app/Models/**'\n  - 'database/migrations/**'\n---\n\n# Models Migrations\n\nDefine casts in the casts() method.\n\nUse UUID primary keys.\n"
            )
            ->and($this->fixture->read('.ai/rules/boost/http.md'))->toBe("---\npaths:\n  - 'app/Http/**'\n---\n\n## Controllers\n\nKeep controllers thin.\n")
            ->and($this->fixture->read('.ai/rules/boost/tests.md'))->toContain('Prefer factories.')
            ->and(indexRows($this->fixture->read('.ai/rules/index.md')))->toBe([
                ['app/Http/**', '.ai/rules/boost/http.md'],
                ['app/Models/**, database/migrations/**', '.ai/rules/boost/models-migrations.md'],
                ['tests/**', '.ai/rules/boost/tests.md'],
                ['app/**', '.ai/rules/team.md'],
            ])
            ->and($this->fixture->configuration()['published']['rules'])->toBe([
                '.ai/rules/boost/models-migrations.md',
                '.ai/rules/boost/http.md',
                '.ai/rules/boost/tests.md',
            ]);
    });

    it('is idempotent once extracted', function () {
        publishRules(true);
        $snapshot = $this->fixture->snapshot();

        runCommand('afterburner:update');

        expect($this->fixture->snapshot())->toBe($snapshot);
    });

    it('replaces generated rules when scopes change and restores content when disabled', function () {
        $userRule = $this->fixture->read('.ai/rules/team.md');
        publishRules(true);

        $this->fixture->guideline('only-scoped.blade.php', "@scoped(['tests/Feature/**'])\nPrefer factories.\n@endscoped\n");
        runCommand('afterburner:update');

        expect($this->fixture->exists('.ai/rules/boost/tests.md'))->toBeFalse()
            ->and($this->fixture->read('.ai/rules/boost/feature.md'))->toContain("  - 'tests/Feature/**'")
            ->and($this->fixture->read('.ai/rules/index.md'))->toContain('.ai/rules/boost/feature.md')->not->toContain('boost/tests.md');

        expect(publishRules(false)->successful())->toBeTrue()
            ->and($this->fixture->read('CLAUDE.md'))->toContain('Define casts in the casts() method.', 'Prefer factories.')
            ->and($this->fixture->exists('.ai/rules/boost'))->toBeFalse()
            ->and(indexRows($this->fixture->read('.ai/rules/index.md')))->toBe([['app/**', '.ai/rules/team.md']])
            ->and($this->fixture->read('.ai/rules/team.md'))->toBe($userRule)
            ->and($this->fixture->configuration()['published']['rules'])->toBe([]);
    });

    it('removes the generated index when no rules remain', function () {
        $this->fixture->delete('.ai/rules/team.md');
        publishRules(true);

        publishRules(false);

        expect($this->fixture->exists('.ai/rules'))->toBeFalse();
    });

    it('removes rules extracted from an excluded or removed package', function () {
        $this->fixture->delete('.ai/guidelines');
        $this->fixture->composerPackage('acme/a', '1.0.0', [
            'resources/boost/guidelines/core.blade.php' => "# Acme\n\n@scoped(['app/Acme/**'])\nAcme rule.\n@endscoped\n",
        ]);

        publishRules(true);

        expect($this->fixture->read('.ai/rules/boost/acme.md'))->toContain('Acme rule.');

        publishRules(true, ['packages' => ['acme/a' => ['guidelines' => false]]]);

        expect($this->fixture->exists('.ai/rules/boost'))->toBeFalse()
            ->and(indexRows($this->fixture->read('.ai/rules/index.md')))->toBe([['app/**', '.ai/rules/team.md']]);

        publishRules(true);
        $this->fixture->removeComposerPackage('acme/a');
        runCommand('afterburner:update');

        expect($this->fixture->exists('.ai/rules/boost/acme.md'))->toBeFalse();
    });

    it('validates selected scoped content before changing rules, guidelines, or the index', function () {
        publishRules(true);
        $before = $this->fixture->snapshot();

        $this->fixture->guideline('broken.blade.php', "@scoped(['x/**'])\n{{ \$assist->missing() }}\n@endscoped\n");
        $broken = $this->fixture->snapshot();

        $result = runCommand('afterburner:update');

        expect($result->exitCode)->toBe(1)
            ->and($result->output)->toContain('.ai/guidelines/broken.blade.php')
            ->and($this->fixture->snapshot())->toBe($broken)
            ->and(array_diff_key($broken, $before))->toHaveKey('.ai/guidelines/broken.blade.php');
    });

    it('refuses to overwrite an untracked rule file in the managed directory', function () {
        $this->fixture->write('.ai/rules/boost/http.md', rule(['app/Http/**'], "# Hand written\n"));
        $before = $this->fixture->snapshot();

        $result = publishRules(true);

        expect($result->exitCode)->toBe(1)
            ->and($result->output)->toContain('.ai/rules/boost/http.md', 'did not generate')
            ->and(array_diff_assoc($this->fixture->snapshot(), $before))->toBe(['afterburner.json' => $this->fixture->snapshot()['afterburner.json']]);
    });

    it('does not rewrite the index over unresolved conflicts', function () {
        $this->fixture->write('.ai/rules/team.md', rule(['app/**'], "<<<<<<< HEAD\nA\n=======\nB\n>>>>>>> x\n"));

        $result = publishRules(true);

        expect($result->exitCode)->toBe(1)
            ->and($result->output)->toContain('unresolved merge conflicts', '.ai/rules/team.md')
            ->and($this->fixture->exists('.ai/rules/boost'))->toBeFalse()
            ->and($this->fixture->exists('CLAUDE.md'))->toBeFalse();
    });
});
