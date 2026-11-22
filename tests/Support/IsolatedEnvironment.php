<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Points HOME and related user-level locations at a disposable directory.
 */
final class IsolatedEnvironment
{
    /** @var array<string, string|false> */
    private array $previous = [];

    private function __construct(private readonly string $home)
    {
        //
    }

    public static function enter(): self
    {
        $environment = new self(TemporaryDirectory::make('afterburner-home-'));

        foreach ([
            'HOME' => $environment->home,
            'XDG_CONFIG_HOME' => $environment->home.'/.config',
            'COMPOSER_DEV_MODE' => null,
            'LARAVEL_SAIL' => null,
        ] as $key => $value) {
            $environment->set($key, $value);
        }

        return $environment;
    }

    public function home(): string
    {
        return $this->home;
    }

    public function set(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->previous)) {
            $this->previous[$key] = getenv($key);
        }

        self::apply($key, $value);
    }

    public function restore(): void
    {
        foreach ($this->previous as $key => $value) {
            self::apply($key, $value === false ? null : $value);
        }

        TemporaryDirectory::remove($this->home);
    }

    private static function apply(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
