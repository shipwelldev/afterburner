<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Replacement;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs Composer as an external process.
 *
 * The binary is AFTERBURNER_COMPOSER when set (an executable, or a .phar/.php
 * file run with the current PHP binary), then the COMPOSER_BINARY that
 * Composer exports to its scripts, then `composer` on the PATH.
 */
final class ComposerRunner
{
    /**
     * @param  array<int, string>  $command
     */
    private function __construct(private readonly array $command)
    {
        //
    }

    /**
     * @throws RuntimeException when no Composer binary can be found
     */
    public static function locate(): self
    {
        foreach (['AFTERBURNER_COMPOSER', 'COMPOSER_BINARY'] as $variable) {
            $configured = getenv($variable);

            if (is_string($configured) && $configured !== '') {
                return new self(self::commandFor($configured));
            }
        }

        $composer = (new ExecutableFinder)->find('composer');

        if ($composer === null) {
            throw new RuntimeException('Composer was not found on the PATH. Install Composer or set AFTERBURNER_COMPOSER to its path, then run the replacement again. Nothing was changed.');
        }

        return new self([$composer]);
    }

    /**
     * Remove the root requirement on laravel/boost.
     *
     * @param  callable(string, string): void  $output
     */
    public function remove(string $package, ?string $section, string $workingDirectory, callable $output): int
    {
        $arguments = ['remove', $package, '--no-interaction', '--no-progress'];

        if ($section === 'require-dev') {
            $arguments[] = '--dev';
        }

        $process = new Process([...$this->command, ...$arguments], $workingDirectory, null, null, null);

        $process->run(function (string $type, string $buffer) use ($output): void {
            $output($type, $buffer);
        });

        return (int) $process->getExitCode();
    }

    public function describe(): string
    {
        return implode(' ', $this->command);
    }

    /**
     * @return array<int, string>
     */
    private static function commandFor(string $binary): array
    {
        return str_ends_with($binary, '.phar') || str_ends_with($binary, '.php')
            ? [PHP_BINARY, $binary]
            : [$binary];
    }
}
