<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * The exit code and complete output of a noninteractive command run.
 */
final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function run(string $command, array $parameters = []): self
    {
        $exitCode = Artisan::call($command, ['--no-interaction' => true, ...$parameters]);

        return new self($exitCode, (string) preg_replace('/\e\[[\d;]*m/', '', Artisan::output()));
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
