<?php

declare(strict_types=1);
use Tests\Support\FixtureApplication;

function renderedBlock(string $document): string
{
    preg_match('/<afterburner-guidelines>\n(.*?)\n\n<\/afterburner-guidelines>/s', $document, $matches);

    return $matches[1] ?? '';
}

function renderGuideline(FixtureApplication $fixture, string $template, string $name = 'dynamic.blade.php'): string
{
    $fixture->guideline($name, $template);

    $result = runCommand('afterburner:install', ['--agents' => 'claude', '--base' => 'none']);

    if (! $result->successful()) {
        throw new RuntimeException($result->output);
    }

    return renderedBlock($fixture->read('CLAUDE.md'));
}

it('renders application helpers from the actual environment', function () {
    $this->fixture->composerPackage('laravel/framework', '13.4.0');
    $this->fixture->write('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
    $manifest = $this->fixture->json('composer.json');
    $manifest['scripts'] = ['dev' => 'npm run dev'];
    $this->fixture->writeJson('composer.json', $manifest);

    $block = renderGuideline($this->fixture, <<<'BLADE'
- Test: `{{ $assist->artisanCommand('test --compact') }}`
- Install: `{{ $assist->composerCommand('install') }}`
- Format: `{{ $assist->binCommand('pint --dirty') }}`
- Build: `{{ $assist->nodePackageManagerCommand('run build') }}`
- Manager: {{ $assist->nodePackageManager() }}
- Artisan: {{ $assist->artisan() }}
- Models: {{ $assist->appPath('Models') }}
- Dev script: {{ $assist->hasComposerScript('dev') ? 'yes' : 'no' }}
- Missing script: {{ $assist->hasComposerScript('missing') ? 'yes' : 'no' }}
- Sail binary: {{ $assist->sailBinaryPath() }}
BLADE);

    expect($block)
        ->toContain('- Test: `php artisan test --compact`')
        ->toContain('- Install: `composer install`')
        ->toContain('- Format: `vendor/bin/pint --dirty`')
        ->toContain('- Build: `pnpm run build`')
        ->toContain('- Manager: pnpm')
        ->toContain('- Artisan: php artisan')
        ->toContain('- Models: app/Models')
        ->toContain('- Dev script: yes')
        ->toContain('- Missing script: no')
        ->toContain('- Sail binary: vendor/bin/sail');
});

it('builds Sail commands when Sail is selected or active', function (Closure $setup, bool $sail) {
    $setup($this->fixture, $this->environment);

    $block = renderGuideline($this->fixture, "{{ \$assist->artisanCommand('migrate') }} | {{ \$assist->composerCommand('test') }} | {{ \$assist->binCommand('pest') }}");

    expect($block)->toContain($sail
        ? 'vendor/bin/sail artisan migrate | vendor/bin/sail composer test | vendor/bin/sail bin pest'
        : 'php artisan migrate | composer test | vendor/bin/pest');
})->with([
    'not installed' => [fn () => null, false],
    'selected' => [fn ($fixture) => $fixture->configure(['integrations' => ['sail' => true]]), true],
    'installed but inactive' => [function ($fixture) {
        $fixture->write('vendor/bin/sail', '#!/bin/sh');
        $fixture->write('compose.yaml', 'services: {}');
    }, false],
    'installed and active' => [function ($fixture, $environment) {
        $fixture->write('vendor/bin/sail', '#!/bin/sh');
        $fixture->write('compose.yaml', 'services: {}');
        $environment->set('LARAVEL_SAIL', '1');
    }, true],
    'active but declined' => [function ($fixture, $environment) {
        $fixture->write('vendor/bin/sail', '#!/bin/sh');
        $fixture->write('docker-compose.yml', 'services: {}');
        $environment->set('LARAVEL_SAIL', '1');
        $fixture->configure(['integrations' => ['sail' => false]]);
    }, false],
]);

it('honors executable overrides from an existing Boost configuration file', function () {
    config()->set('boost.executable_paths', ['php' => '/opt/php', 'composer' => 'composer.phar', 'vendor_bin' => 'bin/', 'npm' => 'bun']);

    $block = renderGuideline($this->fixture, "{{ \$assist->artisanCommand('about') }} {{ \$assist->composerCommand('i') }} {{ \$assist->binCommand('pint') }} {{ \$assist->nodePackageManagerCommand('x') }}");

    expect($block)->toContain('/opt/php artisan about composer.phar i bin/pint bun x');
});

it('reports truthful capability flags', function () {
    $this->fixture->configure(['integrations' => ['cloud' => true]]);

    $block = renderGuideline($this->fixture, <<<'BLADE'
@if($assist->hasMcpEnabled())
MCP enabled
@else
MCP disabled
@endif
Config MCP: {{ $assist->config->hasMcp ? 'on' : 'off' }}
Skills: {{ $assist->hasSkillsEnabled() ? 'on' : 'off' }}
Cloud: {{ $assist->hasCloudEnabled() ? 'on' : 'off' }}
BLADE);

    expect($block)->toContain('MCP disabled')->not->toContain('MCP enabled')
        ->toContain('Config MCP: off')
        ->toContain('Skills: on')
        ->toContain('Cloud: on');
});

it('evaluates package and major version checks for PHP and JavaScript packages', function () {
    $this->fixture
        ->composerPackage('laravel/framework', '13.4.0')
        ->composerPackage('laravel/pint', '1.27.2', dev: true)
        ->composerPackage('phpunit/phpunit', '12.3.0', direct: false, dev: true)
        ->npmPackage('tailwindcss', '4.1.0', dev: true);

    $block = renderGuideline($this->fixture, <<<'BLADE'
@php($phpunit = $assist->project->php()->package('phpunit/phpunit'))
- Laravel 13: {{ $assist->hasPackage('laravel/framework', '^13.0') ? 'yes' : 'no' }}
- Laravel 12: {{ $assist->hasPackage('laravel/framework', '^12.0') ? 'yes' : 'no' }}
- Tailwind 4: {{ $assist->hasPackage('tailwindcss', '>=4.0') ? 'yes' : 'no' }}
- Livewire: {{ $assist->hasPackage('livewire/livewire') ? 'yes' : 'no' }}
- Pint agent formatter: {{ $assist->supportsPintAgentFormatter() ? 'yes' : 'no' }}
- PHPUnit major: {{ $phpunit?->major() }}
- JS uses tailwind: {{ $assist->project->js()->uses('tailwindcss') ? 'yes' : 'no' }}
BLADE);

    expect($block)
        ->toContain('- Laravel 13: yes')
        ->toContain('- Laravel 12: no')
        ->toContain('- Tailwind 4: yes')
        ->toContain('- Livewire: no')
        ->toContain('- Pint agent formatter: yes')
        ->toContain('- PHPUnit major: 12')
        ->toContain('- JS uses tailwind: yes');
});

it('provides Inertia helpers', function (string $version, array $expected) {
    $this->fixture->npmPackage('@inertiajs/vue3', $version);
    $this->fixture->write('resources/js/pages/Home.vue', '');

    $block = renderGuideline($this->fixture, <<<'BLADE'
gte2={{ $assist->inertia()->gte('2.0.0') ? 'y' : 'n' }} form={{ $assist->inertia()->hasFormComponent() ? 'y' : 'n' }} resets={{ $assist->inertia()->hasFormComponentResets() ? 'y' : 'n' }} pages={{ $assist->inertia()->pagesDirectory() }}
BLADE);

    expect($block)->toContain(implode(' ', $expected).' pages=resources/js/pages');
})->with([
    'v1' => ['1.3.0', ['gte2=n', 'form=n', 'resets=n']],
    'v2.1.0' => ['2.1.0', ['gte2=y', 'form=y', 'resets=n']],
    'v2.2.0' => ['2.2.0', ['gte2=y', 'form=y', 'resets=y']],
]);

it('discovers application enums', function () {
    $this->fixture->write('app/Enums/OrderStatus.php', "<?php\n\nnamespace App\\Enums;\n\nenum OrderStatus: string\n{\n    case PENDING_PAYMENT = 'pending';\n}\n");
    $this->fixture->write('app/Models/Order.php', "<?php\n\nnamespace App\\Models;\n\nclass Order {}\n");

    $block = renderGuideline($this->fixture, <<<'BLADE'
Enums: {{ implode(',', array_keys($assist->enums())) }}
@if(preg_match('/^\s*case\s+[A-Z][A-Z0-9_]{2,}/m', $assist->enumContents()))
Follow existing enum naming.
@endif
BLADE);

    expect($block)->toContain('Enums: App\\Enums\\OrderStatus')->toContain('Follow existing enum naming.');
});

it('keeps Markdown literal and protects code examples in templates', function () {
    $this->fixture->guideline('literal.md', "Literal {{ \$assist->artisanCommand('x') }} and @if(true) stay.\n");

    $block = renderGuideline($this->fixture, <<<'BLADE'
Inline `code` and `{{ $assist->artisanCommand('test') }}` render.
Escaped @{{ $value }} and @@if stay literal.

```php
<?php

@can('update', $post)
    <x-button wire:click="save">Save & exit</x-button>
@@endcan
```

@verbatim
Raw {{ $kept }}
@endverbatim
BLADE);

    expect($block)
        ->toContain("Literal {{ \$assist->artisanCommand('x') }} and @if(true) stay.")
        ->toContain('Inline `code` and `php artisan test` render.')
        ->toContain('Escaped {{ $value }} and @if stay literal.')
        ->toContain("<?php\n\n@can('update', \$post)\n    <x-button wire:click=\"save\">Save & exit</x-button>\n@endcan")
        ->toContain('Raw {{ $kept }}');
});

it('renders snippets as labelled fenced code', function () {
    $block = renderGuideline($this->fixture, <<<'BLADE'
@boostsnippet('Running tests', 'bash')
{{ $assist->artisanCommand('test') }} --filter={{ $name }}
@endboostsnippet
BLADE);

    expect($block)->toContain("<!-- Running tests -->\n```bash\nphp artisan test --filter={{ \$name }}\n```");
});

it('keeps scoped content inline by default', function () {
    $block = renderGuideline($this->fixture, <<<'BLADE'
# Models

@scoped(['app/Models/**', 'database/migrations/**'])
Use casts() for attribute casting.
@endscoped

@scoped([])
Empty scope body.
@endscoped

@scoped(['app/**'])
Outer body.
@scoped(['app/Http/**'])
Nested body.
@endscoped
@endscoped

@@scoped(['escaped/**']) is documentation.

```blade
@scoped(['example/**'])
Example
@endscoped
```
BLADE);

    expect($block)
        ->toContain('Use casts() for attribute casting.')
        ->toContain('Empty scope body.')
        ->toContain('Outer body.')
        ->toContain('Nested body.')
        ->toContain("@scoped(['escaped/**']) is documentation.")
        ->toContain("```blade\n@scoped(['example/**'])\nExample\n@endscoped\n```")
        ->not->toContain('@scoped([\'app/Models/**\'')
        ->not->toContain('@endscoped\n\n@scoped([])')
        ->not->toContain('___SCOPED');
});

it('renders Blade skills into agent-readable entry points with rendered metadata', function () {
    $this->fixture->write('.ai/skills/dynamic/SKILL.blade.php', <<<'BLADE'
---
name: {{ 'artisan-' . 'helper' }}
description: Run {{ $assist->artisanCommand('list') }} to discover commands.
---

# Artisan helper

Run `{{ $assist->artisanCommand('route:list') }}`. See [the checklist](references/checklist.md).
BLADE);
    $this->fixture->write('.ai/skills/dynamic/SKILL.md', "---\nname: losing\ndescription: Markdown entry point.\n---\n");
    $this->fixture->write('.ai/skills/dynamic/references/checklist.blade.php', "- Run `{{ \$assist->artisanCommand('test') }}`\n");
    $this->fixture->write('.ai/skills/dynamic/references/notes.md', "Literal {{ \$assist }}\n");

    expect(runCommand('afterburner:install', ['--agents' => 'claude', '--base' => 'none'])->successful())->toBeTrue();

    $skill = $this->fixture->read('.claude/skills/artisan-helper/SKILL.md');

    expect($skill)
        ->toStartWith("---\nname: artisan-helper\ndescription: Run php artisan list to discover commands.\n---")
        ->toContain('Run `php artisan route:list`.')
        ->not->toContain('{{')
        ->not->toContain('Markdown entry point')
        ->and($this->fixture->exists('.claude/skills/losing'))->toBeFalse()
        ->and($this->fixture->exists('.claude/skills/artisan-helper/SKILL.blade.php'))->toBeFalse()
        ->and($this->fixture->read('.claude/skills/artisan-helper/references/checklist.md'))->toBe("- Run `php artisan test`\n")
        ->and($this->fixture->exists('.claude/skills/artisan-helper/references/checklist.blade.php'))->toBeFalse()
        ->and($this->fixture->read('.claude/skills/artisan-helper/references/notes.md'))->toBe("Literal {{ \$assist }}\n")
        ->and(glob($this->fixture->path('storage/framework/views/*')))->toBe([]);
});

it('prefers an application Blade override over its Markdown variant', function () {
    $this->fixture
        ->composerPackage('acme/a', '1.0.0', ['resources/boost/guidelines/core.md' => "Vendor.\n"])
        ->guideline('acme/a/core.md', "Markdown override.\n")
        ->guideline('acme/a/core.blade.php', "Blade override {{ \$assist->artisan() }}.\n");

    runCommand('afterburner:install', ['--agents' => 'claude', '--base' => 'none']);

    expect(renderedBlock($this->fixture->read('CLAUDE.md')))
        ->toContain("=== acme/a/core rules ===\n\nBlade override php artisan.")
        ->not->toContain('Markdown override.')
        ->not->toContain('Vendor.');
});

it('fails on invalid selected templates and metadata without changing previous output', function (Closure $break, string $message) {
    $this->fixture->guideline('app.md', "App.\n")->skill('existing');
    runCommand('afterburner:install', ['--agents' => 'claude,codex', '--base' => 'none']);

    $break($this->fixture);
    $before = $this->fixture->snapshot();
    $views = glob($this->fixture->path('storage/framework/views/*'));

    $result = runCommand('afterburner:update');

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain($message)
        ->and($this->fixture->snapshot())->toBe($before)
        ->and(glob($this->fixture->path('storage/framework/views/*')))->toBe($views);
})->with([
    'guideline helper error' => [
        fn ($fixture) => $fixture->guideline('broken.blade.php', "{{ \$assist->doesNotExist() }}\n"),
        '.ai/guidelines/broken.blade.php: the template failed to render: Call to undefined method',
    ],
    'guideline syntax error' => [
        fn ($fixture) => $fixture->guideline('broken.blade.php', "@if(\n"),
        '.ai/guidelines/broken.blade.php: the template failed to render',
    ],
    'skill entry error' => [
        fn ($fixture) => $fixture->write('.ai/skills/broken/SKILL.blade.php', "---\nname: broken\ndescription: x\n---\n{{ throw new RuntimeException('boom') }}\n"),
        '.ai/skills/broken/SKILL.blade.php: the template failed to render: boom',
    ],
    'rendered metadata missing' => [
        fn ($fixture) => $fixture->write('.ai/skills/broken/SKILL.blade.php', "---\nname: broken\ndescription: {{ '' }}\n---\n"),
        'nonempty `description`',
    ],
    'supporting template error' => [
        function ($fixture) {
            $fixture->skill('helper');
            $fixture->write('.ai/skills/helper/references/broken.blade.php', "{{ \$undefined->call() }}\n");
        },
        '.ai/skills/helper/references/broken.blade.php: the template failed to render',
    ],
    'rendered name collision' => [
        function ($fixture) {
            $fixture->skill('helper');
            $fixture->write('.ai/skills/helper/references/guide.blade.php', "Rendered.\n");
            $fixture->write('.ai/skills/helper/references/guide.md', "Literal.\n");
        },
        'it would publish as references/guide.md',
    ],
]);

it('does not render guidelines replaced by an application override', function () {
    $this->fixture
        ->composerPackage('acme/a', '1.0.0', ['resources/boost/guidelines/core.blade.php' => "{{ \$assist->doesNotExist() }}\n"])
        ->guideline('acme/a/core.md', "Application replacement.\n");

    expect(runCommand('afterburner:install', ['--agents' => 'claude', '--base' => 'none'])->successful())->toBeTrue()
        ->and(renderedBlock($this->fixture->read('CLAUDE.md')))->toContain('Application replacement.');
});

it('does not render excluded dependency templates', function () {
    $this->fixture
        ->composerPackage('acme/a', '1.0.0', [
            'resources/boost/guidelines/core.blade.php' => "{{ \$assist->doesNotExist() }}\n",
            'resources/boost/skills/broken/SKILL.blade.php' => "{{ throw new RuntimeException('boom') }}\n",
        ])
        ->configure(['packages' => ['acme/a' => ['guidelines' => false, 'skills' => false]]])
        ->guideline('app.md', "App.\n");

    expect(runCommand('afterburner:install', ['--agents' => 'claude', '--base' => 'none'])->successful())->toBeTrue();
});

it('fails when a candidate skill identity cannot be established even if an override might exist', function () {
    $this->fixture
        ->composerPackage('acme/a', '1.0.0', [
            'resources/boost/skills/vendor-skill/SKILL.blade.php' => "---\nname: {{ \$assist->missing() }}\ndescription: x\n---\n",
        ])
        ->skill('vendor-skill', 'Application version.');

    $result = runCommand('afterburner:install', ['--agents' => 'claude', '--base' => 'none']);

    expect($result->exitCode)->toBe(1)
        ->and($result->output)->toContain('vendor/acme/a/resources/boost/skills/vendor-skill/SKILL.blade.php')
        ->and($this->fixture->exists('.claude'))->toBeFalse();
});

it('supports Boost package registry constants in templates without Boost installed', function () {
    expect(class_exists('Laravel\\Boost\\BoostServiceProvider'))->toBeFalse();

    $this->fixture->npmPackage('@inertiajs/vue3', '2.1.0');

    $block = renderGuideline($this->fixture, <<<'BLADE'
@if($assist->hasPackage(\Laravel\Boost\Support\PackageRegistry::INERTIA_VUE))
Vue: {{ \Laravel\Boost\Support\PackageRegistry::guidelineName(\Laravel\Boost\Support\PackageRegistry::INERTIA_VUE) }}
@endif
@if($assist->project->js()->uses([\Laravel\Boost\Support\PackageRegistry::INERTIA_REACT, \Laravel\Boost\Support\PackageRegistry::INERTIA_SVELTE]))
React or Svelte
@endif
BLADE);

    expect($block)->toContain('Vue: inertia-vue')->not->toContain('React or Svelte');
});
