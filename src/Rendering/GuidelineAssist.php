<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Rendering;

use Illuminate\Support\Collection;
use Laravel\Roster\Enums\JsPackageManager;
use Laravel\Roster\ProjectManager;
use Symfony\Component\Finder\Finder;

/**
 * The `$assist` authoring surface available to Blade guidelines and skills.
 *
 * Method names and behavior match Laravel Boost v2.10.4's GuidelineAssist so
 * unchanged package templates render without Boost installed. Values come
 * from the actual application: its installed packages, Composer scripts,
 * Sail usage, and executable overrides. MCP is always reported as disabled.
 */
final class GuidelineAssist
{
    /** @var array<string, string>|null */
    private ?array $enumPaths = null;

    /**
     * @param  Collection<string, string>  $skills  selected skill names => descriptions
     */
    public function __construct(
        public ProjectManager $project,
        public GuidelineConfig $config,
        public Collection $skills,
        private readonly string $basePath,
    ) {
        //
    }

    /**
     * Enum classes in the application directory.
     *
     * @return array<string, string> class name => absolute path
     */
    public function enums(): array
    {
        return $this->enumPaths ??= $this->discoverEnums();
    }

    public function enumContents(): string
    {
        return collect($this->enums())
            ->sortKeys()
            ->map(fn (string $path): string => is_file($path) ? (file_get_contents($path) ?: '') : '')
            ->filter()
            ->join(PHP_EOL);
    }

    public function inertia(): InertiaAssist
    {
        return new InertiaAssist($this->project, $this->basePath);
    }

    public function supportsPintAgentFormatter(): bool
    {
        return $this->project->php()->uses('laravel/pint', '>=1.27.0');
    }

    public function hasPackage(string $package, ?string $constraint = null): bool
    {
        return $this->project->php()->uses($package, $constraint)
            || $this->project->js()->uses($package, $constraint);
    }

    public function nodePackageManager(): string
    {
        return ($this->project->js()->packageManager() ?? JsPackageManager::Npm)->value;
    }

    public function nodePackageManagerCommand(string $command): string
    {
        if ($executable = self::executable('npm')) {
            return "{$executable} {$command}";
        }

        if ($this->config->usesSail) {
            return Sail::command($this->nodePackageManager())." {$command}";
        }

        return "{$this->nodePackageManager()} {$command}";
    }

    public function artisanCommand(string $command): string
    {
        return "{$this->artisan()} {$command}";
    }

    public function composerCommand(string $command): string
    {
        if ($executable = self::executable('composer')) {
            return "{$executable} {$command}";
        }

        if ($this->config->usesSail) {
            return Sail::command('composer')." {$command}";
        }

        return "composer {$command}";
    }

    public function hasComposerScript(string $script): bool
    {
        $path = $this->basePath.DIRECTORY_SEPARATOR.'composer.json';
        $composer = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($composer) && is_array($composer['scripts'] ?? null) && array_key_exists($script, $composer['scripts']);
    }

    public function binCommand(string $command): string
    {
        if ($prefix = self::executable('vendor_bin')) {
            return "{$prefix}{$command}";
        }

        if ($this->config->usesSail) {
            return Sail::command('bin ').$command;
        }

        return "vendor/bin/{$command}";
    }

    public function artisan(): string
    {
        if ($executable = self::executable('php')) {
            return "{$executable} artisan";
        }

        return $this->config->usesSail ? Sail::command('artisan') : 'php artisan';
    }

    public function sailBinaryPath(): string
    {
        return Sail::binaryPath();
    }

    public function appPath(string $path = ''): string
    {
        $relative = ltrim(substr(app_path($path), strlen($this->basePath)), DIRECTORY_SEPARATOR);

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    public function hasSkillsEnabled(): bool
    {
        return $this->config->hasSkills;
    }

    public function hasMcpEnabled(): bool
    {
        return false;
    }

    public function hasCloudEnabled(): bool
    {
        return $this->config->usesCloud;
    }

    /**
     * An executable override from an existing `config/boost.php`, kept for compatibility.
     */
    private static function executable(string $name): ?string
    {
        $value = config("boost.executable_paths.{$name}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function applicationNamespace(): string
    {
        try {
            return app()->getNamespace();
        } catch (\Throwable) {
            return 'App\\';
        }
    }

    /**
     * @return array<string, string>
     */
    private function discoverEnums(): array
    {
        $appPath = app_path();

        if (! is_dir($appPath)) {
            return [];
        }

        $enums = [];

        foreach (Finder::create()->in($appPath)->files()->name('/[A-Z].*\.php$/') as $file) {
            $code = @file_get_contents($file->getRealPath());

            if ($code === false || stripos($code, 'enum') === false) {
                continue;
            }

            foreach (token_get_all($code) as $token) {
                if (is_array($token) && $token[0] === T_ENUM) {
                    $class = self::applicationNamespace().str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
                    $enums[$class] = $file->getRealPath();

                    break;
                }
            }
        }

        return $enums;
    }
}
