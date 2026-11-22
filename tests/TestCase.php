<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Shipwell\Afterburner\AfterburnerServiceProvider;
use Tests\Support\FixtureApplication;
use Tests\Support\IsolatedEnvironment;

/**
 * Boots the package inside a fresh fixture Laravel application per test.
 *
 * The fixture root is the application's base path, HOME points at a
 * temporary directory, and caches/sessions use in-memory drivers, so tests
 * never read or write the developer's real application, home directory, or
 * globally installed agent configuration.
 */
abstract class TestCase extends Orchestra
{
    public FixtureApplication $fixture;

    public IsolatedEnvironment $environment;

    protected function setUp(): void
    {
        $this->fixture = FixtureApplication::create();
        $this->environment = IsolatedEnvironment::enter();

        $this->beforeApplicationBoots();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->environment->restore();
        $this->fixture->destroy();
    }

    /**
     * Prepare fixture files that must exist before the application boots.
     */
    protected function beforeApplicationBoots(): void
    {
        //
    }

    protected function getApplicationBasePath()
    {
        return $this->fixture->path();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app)
    {
        return [AfterburnerServiceProvider::class];
    }

    /**
     * Rebuild the application so the next command runs in a fresh process-like state.
     */
    protected function rebootApplication(): void
    {
        $this->refreshApplication();
    }
}
