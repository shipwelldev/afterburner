<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A real Laravel host application that installs this package through Composer
 * and runs `php artisan` in a separate process.
 *
 * Installing Laravel is slow, so one Composer-installed template is built per
 * package/runtime combination and cached in the system temporary directory.
 * Each test receives its own application root that shares the template's
 * vendor directory, while its own files, HOME, and caches stay isolated.
 */
final class HostProject
{
    private const TEMPLATE_VERSION = '1';

    private function __construct(
        private readonly FixtureApplication $application,
        private readonly string $home,
    ) {
        //
    }

    /**
     * Create a host application that has the package installed through a Composer path repository.
     */
    public static function create(bool $isolatedVendor = false): self
    {
        $template = self::template();
        $application = FixtureApplication::create('afterburner-host-');

        foreach (['composer.json', 'composer.lock'] as $file) {
            copy($template.'/'.$file, $application->path($file));
        }

        if ($isolatedVendor) {
            self::isolateVendor($template.'/vendor', $application->path('vendor'));
        } else {
            symlink($template.'/vendor', $application->path('vendor'));
        }
        self::writeSkeleton($application);

        $host = new self($application, TemporaryDirectory::make('afterburner-host-home-'));

        $discover = $host->artisan(['package:discover']);

        if (! $discover->isSuccessful()) {
            throw new RuntimeException('Unable to discover packages in host project: '.$discover->getErrorOutput().$discover->getOutput());
        }

        return $host;
    }

    /**
     * Give the host its own vendor tree: package directories are links into the
     * template, while vendor/composer (autoloader and installed.json) is a copy,
     * so a test can add or remove packages without touching the shared template.
     */
    private static function isolateVendor(string $source, string $target): void
    {
        mkdir($target, 0755, true);

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $from = $source.'/'.$entry;

            if ($entry === 'composer') {
                (new Process(['cp', '-R', $from, $target.'/composer']))->mustRun();
            } elseif (is_dir($from) && ! is_link($from) && $entry !== 'bin') {
                mkdir($target.'/'.$entry, 0755, true);

                foreach (scandir($from) ?: [] as $package) {
                    if ($package !== '.' && $package !== '..') {
                        symlink($from.'/'.$package, $target.'/'.$entry.'/'.$package);
                    }
                }
            } elseif (is_dir($from)) {
                symlink($from, $target.'/'.$entry);
            } else {
                copy($from, $target.'/'.$entry);
            }
        }
    }

    /**
     * Mark laravel/boost as installed: root requirement, lock entry, installed.json entry, and a package directory.
     */
    public function installFakeBoost(string $section = 'require-dev'): self
    {
        $composer = $this->application->json('composer.json');
        $composer[$section]['laravel/boost'] = '^2.10';
        $this->application->writeJson('composer.json', $composer);

        $lock = $this->application->json('composer.lock');
        $lock[$section === 'require-dev' ? 'packages-dev' : 'packages'][] = ['name' => 'laravel/boost', 'version' => 'v2.10.4', 'type' => 'library'];
        $this->application->writeJson('composer.lock', $lock);

        $installed = $this->application->json('vendor/composer/installed.json');
        $installed['packages'][] = ['name' => 'laravel/boost', 'version' => 'v2.10.4', 'version_normalized' => '2.10.4.0', 'type' => 'library', 'install-path' => '../laravel/boost'];
        $this->application->writeJson('vendor/composer/installed.json', $installed);

        $this->application->writeJson('vendor/laravel/boost/composer.json', ['name' => 'laravel/boost']);

        return $this;
    }

    public function application(): FixtureApplication
    {
        return $this->application;
    }

    public function path(string $relative = ''): string
    {
        return $this->application->path($relative);
    }

    /**
     * Run `php artisan` with the given arguments in a fresh process.
     *
     * @param  array<int, string>  $arguments
     * @param  array<string, string>  $environment
     */
    public function artisan(array $arguments, array $environment = [], ?string $input = null): Process
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', ...$arguments],
            $this->application->path(),
            [...self::processEnvironment($this->home), ...$environment],
            $input,
            120,
        );

        $process->run();

        return $process;
    }

    public function destroy(): void
    {
        TemporaryDirectory::remove($this->home);
        $this->application->destroy();
    }

    /**
     * @return array<string, string|false>
     */
    public static function processEnvironment(string $home): array
    {
        return [
            'HOME' => $home,
            'XDG_CONFIG_HOME' => $home.'/.config',
            'APP_ENV' => 'local',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'LOG_CHANNEL' => 'stderr',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'COMPOSER_DEV_MODE' => false,
            'LARAVEL_SAIL' => false,
            'APP_BASE_PATH' => false,
        ];
    }

    /**
     * Build (or reuse) the Composer-installed host template.
     */
    public static function template(): string
    {
        $packageRoot = dirname(__DIR__, 2);
        $hash = substr(sha1(implode('|', [
            self::TEMPLATE_VERSION,
            PHP_VERSION,
            (string) file_get_contents($packageRoot.'/composer.json'),
            $packageRoot,
        ])), 0, 12);

        $template = sys_get_temp_dir().'/afterburner-host-template-'.$hash;

        if (is_file($template.'/vendor/autoload.php') && is_file($template.'/.ready')) {
            return $template;
        }

        TemporaryDirectory::remove($template);
        mkdir($template, 0755, true);

        $application = FixtureApplication::at($template);

        $application->writeJson('composer.json', [
            'name' => 'fixture/host',
            'type' => 'project',
            'require' => [
                'php' => '^8.4',
                'laravel/framework' => '^13.0',
                'shipwelldev/afterburner' => '*@dev',
            ],
            'repositories' => [
                ['type' => 'path', 'url' => $packageRoot, 'options' => ['symlink' => true]],
            ],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'scripts' => [
                'post-autoload-dump' => [
                    'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
                    '@php artisan package:discover --ansi',
                ],
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'config' => ['sort-packages' => true],
        ]);

        self::writeSkeleton($application);

        $composer = self::composer(['install', '--no-interaction', '--no-progress', '--prefer-dist'], $template);

        if (! $composer->isSuccessful()) {
            throw new RuntimeException("Unable to build the host project template:\n".$composer->getErrorOutput());
        }

        touch($template.'/.ready');

        return $template;
    }

    /**
     * A standalone host with its own vendor tree and the real laravel/boost v2.10.4
     * installed by Composer, for smoke-testing a real `composer remove`.
     */
    public static function createWithRealBoost(): self
    {
        $template = self::template();
        $boostTemplate = $template.'-boost';

        if (! is_file($boostTemplate.'/.ready')) {
            TemporaryDirectory::remove($boostTemplate);
            (new Process(['cp', '-a', $template, $boostTemplate]))->mustRun();
            @unlink($boostTemplate.'/.ready');

            $require = self::composer(['require', 'laravel/boost:2.10.4', '--dev', '--no-interaction', '--no-progress'], $boostTemplate);

            if (! $require->isSuccessful()) {
                throw new RuntimeException("Unable to install laravel/boost into the host template:\n".$require->getErrorOutput());
            }

            touch($boostTemplate.'/.ready');
        }

        // Same directory depth as the template, so Composer's relative path-repository link stays valid.
        $root = sys_get_temp_dir().'/afterburner-real-'.bin2hex(random_bytes(6));
        (new Process(['cp', '-a', $boostTemplate, $root]))->mustRun();
        @unlink($root.'/.ready');

        return new self(FixtureApplication::at($root), TemporaryDirectory::make('afterburner-host-home-'));
    }

    /**
     * Run Composer in this host with the isolated Composer home and shared download cache.
     *
     * @param  array<int, string>  $arguments
     */
    public function runComposer(array $arguments): Process
    {
        return self::composer($arguments, $this->application->path(), ['HOME' => $this->home]);
    }

    /**
     * Environment for an Artisan process that may itself run Composer.
     *
     * @return array<string, string>
     */
    public static function composerEnvironment(): array
    {
        $configured = getenv('AFTERBURNER_TEST_COMPOSER');

        return [
            'COMPOSER_HOME' => self::composerHome(),
            'COMPOSER_CACHE_DIR' => self::composerCacheDirectory(),
            'COMPOSER_NO_INTERACTION' => '1',
            ...(is_string($configured) && $configured !== '' ? ['AFTERBURNER_COMPOSER' => $configured] : []),
        ];
    }

    /**
     * Run Composer with an isolated home directory, reusing the developer/CI download cache.
     *
     * @param  array<int, string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    public static function composer(array $arguments, string $workingDirectory, array $environment = []): Process
    {
        $process = new Process(
            [...self::composerBinary(), ...$arguments],
            $workingDirectory,
            [
                'COMPOSER_HOME' => self::composerHome(),
                'COMPOSER_CACHE_DIR' => self::composerCacheDirectory(),
                'COMPOSER_NO_INTERACTION' => '1',
                'COMPOSER_AUTH' => false,
                ...$environment,
            ],
            null,
            900,
        );

        $process->run();

        return $process;
    }

    /**
     * @return array<int, string>
     */
    private static function composerBinary(): array
    {
        $configured = getenv('AFTERBURNER_TEST_COMPOSER');

        if (is_string($configured) && $configured !== '') {
            return [PHP_BINARY, $configured];
        }

        $finder = new ExecutableFinder;
        $composer = $finder->find('composer');

        if ($composer === null) {
            throw new RuntimeException('Composer is required to build host projects. Set AFTERBURNER_TEST_COMPOSER to a composer.phar path.');
        }

        return [$composer];
    }

    private static function composerHome(): string
    {
        $home = sys_get_temp_dir().'/afterburner-composer-home';

        if (! is_dir($home)) {
            mkdir($home, 0755, true);
        }

        return $home;
    }

    private static function composerCacheDirectory(): string
    {
        $configured = getenv('COMPOSER_CACHE_DIR');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        $process = new Process([...self::composerBinary(), 'config', '--global', 'cache-dir']);
        $process->run();

        $directory = trim($process->getOutput());

        return $resolved = ($process->isSuccessful() && $directory !== '')
            ? $directory
            : sys_get_temp_dir().'/afterburner-composer-cache';
    }

    private static function writeSkeleton(FixtureApplication $application): void
    {
        $application->write('artisan', <<<'PHP'
#!/usr/bin/env php
<?php

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

require __DIR__.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$status = $app->handleCommand(new ArgvInput);

exit($status);
PHP);

        $application->write('bootstrap/app.php', <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
PHP);

        $application->write('routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', fn () => 'host');\n");
        $application->write('routes/console.php', "<?php\n");

        foreach (['app', 'bootstrap/cache', 'storage/app', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
            if (! is_dir($application->path($directory))) {
                mkdir($application->path($directory), 0755, true);
            }
        }
    }
}
