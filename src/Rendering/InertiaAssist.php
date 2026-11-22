<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Rendering;

use Laravel\Roster\ProjectManager;

/**
 * Inertia helpers exposed to templates as `$assist->inertia()`.
 */
final class InertiaAssist
{
    public function __construct(
        private readonly ProjectManager $project,
        private readonly string $basePath,
    ) {
        //
    }

    public function gte(string $version): bool
    {
        return $this->project->php()->uses('inertiajs/inertia-laravel', ">={$version}")
            || $this->project->js()->uses('@inertiajs/react', ">={$version}")
            || $this->project->js()->uses('@inertiajs/svelte', ">={$version}")
            || $this->project->js()->uses('@inertiajs/vue3', ">={$version}");
    }

    public function hasFormComponent(): bool
    {
        return $this->gte('2.1.0');
    }

    public function hasFormComponentResets(): bool
    {
        return $this->gte('2.1.2');
    }

    public function pagesDirectory(): string
    {
        $entries = is_dir($this->basePath.'/resources/js') ? (@scandir($this->basePath.'/resources/js') ?: []) : [];

        return in_array('pages', $entries, true) ? 'resources/js/pages' : 'resources/js/Pages';
    }
}
