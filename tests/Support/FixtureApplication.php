<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * An isolated, disposable Laravel application root used by the command tests.
 *
 * Each fixture lives in its own temporary directory and carries its own
 * application sources, dependency metadata, agent files, and databases, so
 * no test depends on another test, the package checkout, or the developer's
 * machine.
 */
final class FixtureApplication
{
    private function __construct(private readonly string $root)
    {
        //
    }

    public static function create(string $prefix = 'afterburner-fixture-'): self
    {
        $root = TemporaryDirectory::make($prefix);

        foreach ([
            'app',
            'bootstrap/cache',
            'config',
            'database',
            'routes',
            'storage/app',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
        ] as $directory) {
            mkdir($root.'/'.$directory, 0755, true);
        }

        return new self($root);
    }

    /**
     * Wrap an existing directory without taking ownership of its lifecycle.
     */
    public static function at(string $root): self
    {
        return new self(rtrim($root, '/'));
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->root : $this->root.'/'.ltrim($relative, '/');
    }

    public function write(string $relative, string $contents): self
    {
        $path = $this->path($relative);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);

        return $this;
    }

    /**
     * @param  array<mixed>  $data
     */
    public function writeJson(string $relative, array $data): self
    {
        return $this->write($relative, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    public function read(string $relative): string
    {
        $contents = @file_get_contents($this->path($relative));

        if ($contents === false) {
            throw new RuntimeException("Fixture file [{$relative}] does not exist.");
        }

        return $contents;
    }

    /**
     * @return array<mixed>
     */
    public function json(string $relative): array
    {
        $decoded = json_decode($this->read($relative), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Fixture file [{$relative}] does not contain a JSON object.");
        }

        return $decoded;
    }

    public function exists(string $relative): bool
    {
        return file_exists($this->path($relative)) || is_link($this->path($relative));
    }

    public function delete(string $relative): self
    {
        $path = $this->path($relative);

        if (is_dir($path) && ! is_link($path)) {
            TemporaryDirectory::remove($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }

        return $this;
    }

    /**
     * Write the application's afterburner.json.
     *
     * @param  array<mixed>  $configuration
     */
    public function configure(array $configuration): self
    {
        return $this->writeJson('afterburner.json', $configuration);
    }

    /**
     * Replace the preferences in afterburner.json while keeping its generated publication state.
     *
     * @param  array<mixed>  $preferences
     */
    public function reconfigure(array $preferences): self
    {
        $existing = $this->exists('afterburner.json') ? $this->configuration() : [];

        if (isset($existing['published'])) {
            $preferences['published'] = ['skills' => (object) ($existing['published']['skills'] ?? [])] + $existing['published'];
        }

        return $this->configure($preferences);
    }

    /**
     * @return array<mixed>
     */
    public function configuration(): array
    {
        return $this->json('afterburner.json');
    }

    public function guideline(string $relative, string $contents): self
    {
        return $this->write('.ai/guidelines/'.$relative, $contents);
    }

    /**
     * Write a skill folder with a SKILL.md entry point at the given location.
     *
     * @param  array<string, string>  $files  supporting files relative to the skill folder
     */
    public function skillAt(string $directory, string $name, string $description, string $body = '', array $files = [], string $entry = 'SKILL.md'): self
    {
        $this->write(
            $directory.'/'.$entry,
            "---\nname: {$name}\ndescription: {$description}\n---\n\n".($body === '' ? "# {$name}\n" : $body),
        );

        foreach ($files as $relative => $contents) {
            $this->write($directory.'/'.$relative, $contents);
        }

        return $this;
    }

    /**
     * Write an explicit application skill beneath .ai/skills.
     *
     * @param  array<string, string>  $files
     */
    public function skill(string $name, string $description = 'A test skill.', string $body = '', array $files = [], ?string $directory = null): self
    {
        return $this->skillAt('.ai/skills/'.($directory ?? $name), $name, $description, $body, $files);
    }

    /**
     * Declare an installed Composer dependency, its lock entry, and its files.
     *
     * @param  array<string, string>  $files  files relative to the package's installed directory
     */
    public function composerPackage(string $name, string $version = '1.0.0', array $files = [], bool $direct = true, bool $dev = false): self
    {
        $manifest = $this->exists('composer.json') ? $this->json('composer.json') : ['name' => 'fixture/app'];
        $lock = $this->exists('composer.lock') ? $this->json('composer.lock') : ['packages' => [], 'packages-dev' => []];

        $section = $dev ? 'require-dev' : 'require';
        $lockSection = $dev ? 'packages-dev' : 'packages';

        if ($direct) {
            $manifest[$section] ??= [];
            $manifest[$section][$name] = '^'.explode('.', $version)[0].'.0';
        }

        $lock[$lockSection] = array_values(array_filter(
            $lock[$lockSection] ?? [],
            fn (array $package): bool => $package['name'] !== $name,
        ));
        $lock[$lockSection][] = ['name' => $name, 'version' => 'v'.$version];

        $this->writeJson('composer.json', $manifest);
        $this->writeJson('composer.lock', $lock);

        foreach ($files as $relative => $contents) {
            $this->write('vendor/'.$name.'/'.$relative, $contents);
        }

        if ($files === [] && ! is_dir($this->path('vendor/'.$name))) {
            mkdir($this->path('vendor/'.$name), 0755, true);
        }

        return $this;
    }

    public function removeComposerPackage(string $name): self
    {
        $manifest = $this->json('composer.json');
        $lock = $this->json('composer.lock');

        unset($manifest['require'][$name], $manifest['require-dev'][$name]);

        foreach (['packages', 'packages-dev'] as $section) {
            $lock[$section] = array_values(array_filter(
                $lock[$section] ?? [],
                fn (array $package): bool => $package['name'] !== $name,
            ));
        }

        $this->writeJson('composer.json', $manifest);
        $this->writeJson('composer.lock', $lock);

        return $this->delete('vendor/'.$name);
    }

    /**
     * Declare an installed NPM dependency, its lock entry, and its files.
     *
     * @param  array<string, string>  $files  files relative to the package's node_modules directory
     */
    public function npmPackage(string $name, string $version = '1.0.0', array $files = [], bool $direct = true, bool $dev = false): self
    {
        $manifest = $this->exists('package.json') ? $this->json('package.json') : ['name' => 'fixture-app', 'private' => true];
        $lock = $this->exists('package-lock.json')
            ? $this->json('package-lock.json')
            : ['name' => 'fixture-app', 'lockfileVersion' => 3, 'requires' => true, 'packages' => ['' => ['name' => 'fixture-app']]];

        if ($direct) {
            $section = $dev ? 'devDependencies' : 'dependencies';
            $manifest[$section] ??= [];
            $manifest[$section][$name] = '^'.$version;
            $lock['packages'][''][$section] ??= [];
            $lock['packages'][''][$section][$name] = '^'.$version;
        }

        $lock['packages']['node_modules/'.$name] = array_filter([
            'version' => $version,
            'dev' => $dev ?: null,
        ]);

        $this->writeJson('package.json', $manifest);
        $this->writeJson('package-lock.json', $lock);

        foreach ($files as $relative => $contents) {
            $this->write('node_modules/'.$name.'/'.$relative, $contents);
        }

        return $this;
    }

    /**
     * Content hashes of every file below the fixture root, keyed by relative path.
     *
     * Framework caches and compiled views are excluded because they are not
     * application output.
     *
     * @return array<string, string>
     */
    public function snapshot(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($this->root) + 1);

            if (str_starts_with($relative, 'storage/') || str_starts_with($relative, 'bootstrap/cache/')) {
                continue;
            }

            if ($file->isLink()) {
                $files[$relative] = 'link:'.readlink($file->getPathname());
            } elseif ($file->isDir()) {
                $files[$relative.'/'] = 'dir';
            } else {
                $files[$relative] = @sha1_file($file->getPathname()) ?: 'unreadable';
            }
        }

        ksort($files);

        return $files;
    }

    public function destroy(): void
    {
        TemporaryDirectory::remove($this->root);
    }
}
