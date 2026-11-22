<?php

/**
 * A controlled stand-in for `composer remove laravel/boost` used by the replacement tests.
 *
 * FAKE_COMPOSER_MODE selects the behavior:
 * - remove (default): drop the requirement, lock entry, installed.json entry, and package directory, then run post-update-cmd scripts
 * - blocked: drop the root requirement but leave Boost installed, as Composer does when another package requires it
 * - fail: change nothing and exit with an error
 * - script-fails: remove Boost, then fail the way a failing post-update-cmd script does
 *
 * Every invocation's arguments are appended to FAKE_COMPOSER_LOG.
 */
$arguments = array_slice($argv, 1);
$log = getenv('FAKE_COMPOSER_LOG');

if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode($arguments).PHP_EOL, FILE_APPEND);
}

$mode = getenv('FAKE_COMPOSER_MODE') ?: 'remove';
$root = getcwd();

if (($arguments[0] ?? null) !== 'remove' || ($arguments[1] ?? null) !== 'laravel/boost') {
    fwrite(STDERR, 'fake-composer only supports `remove laravel/boost`.'.PHP_EOL);
    exit(64);
}

if ($mode === 'fail') {
    fwrite(STDERR, "Your requirements could not be resolved to an installable set of packages.\n");
    exit(2);
}

$write = static fn (string $file, array $data) => file_put_contents($root.'/'.$file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
$read = static fn (string $file): array => json_decode((string) file_get_contents($root.'/'.$file), true) ?: [];

$composer = $read('composer.json');
unset($composer['require']['laravel/boost'], $composer['require-dev']['laravel/boost']);
$write('composer.json', $composer);

echo "./composer.json has been updated\n";

if ($mode === 'blocked') {
    fwrite(STDERR, "Removal failed, laravel/boost is still present, it may be required by another package. See `composer why laravel/boost`.\n");
    exit(2);
}

$lock = $read('composer.lock');

foreach (['packages', 'packages-dev'] as $section) {
    $lock[$section] = array_values(array_filter($lock[$section] ?? [], fn (array $package) => $package['name'] !== 'laravel/boost'));
}

$write('composer.lock', $lock);

$installed = $read('vendor/composer/installed.json');
$installed['packages'] = array_values(array_filter($installed['packages'] ?? [], fn (array $package) => $package['name'] !== 'laravel/boost'));
$write('vendor/composer/installed.json', $installed);

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && ! is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $remove($path.'/'.$entry);
        }

        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
};

$remove($root.'/vendor/laravel/boost');

echo "  - Removing laravel/boost (v2.10.4)\n";

putenv('COMPOSER_DEV_MODE=1');

passthru(escapeshellarg(PHP_BINARY).' artisan package:discover --ansi', $discovered);

foreach ((array) ($composer['scripts']['post-update-cmd'] ?? []) as $script) {
    if (! str_starts_with($script, '@php ')) {
        continue;
    }

    echo "> {$script}\n";
    passthru(escapeshellarg(PHP_BINARY).' '.substr($script, 5), $code);

    if ($code !== 0) {
        fwrite(STDERR, "Script {$script} handling the post-update-cmd event returned with error code {$code}\n");
        exit($code);
    }
}

exit($mode === 'script-fails' ? 1 : 0);
