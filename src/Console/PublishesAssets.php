<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Console;

use Shipwell\Afterburner\Configuration\ConfigurationFile;
use Shipwell\Afterburner\Configuration\Settings;
use Shipwell\Afterburner\Discovery\PackageInventory;
use Shipwell\Afterburner\Installation\Selection;
use Shipwell\Afterburner\Publication\Adoption;
use Shipwell\Afterburner\Publication\Plan;
use Shipwell\Afterburner\Publication\Planner;
use Shipwell\Afterburner\Publication\PublicationFailed;
use Shipwell\Afterburner\Publication\Renderer;
use Shipwell\Afterburner\Publication\SourceCatalog;
use Shipwell\Afterburner\Publication\WriteFailed;
use Shipwell\Afterburner\Publication\Writer;
use Shipwell\Afterburner\Rendering\BladeRenderer;
use Shipwell\Afterburner\Rendering\RenderingContext;

/**
 * The single validate-then-publish path shared by installation and updates.
 */
trait PublishesAssets
{
    /**
     * Validate and publish the selection, then persist choices and ownership.
     */
    protected function publish(ConfigurationFile $configuration, Selection $selection, ?Adoption $adoption = null): int
    {
        $basePath = $this->laravel->basePath();
        $settings = $configuration->settings();
        $previous = $settings->published;

        $inventory = PackageInventory::scan();
        $planner = new Planner($basePath, $this->renderer($basePath, $settings, $inventory));

        try {
            $plan = $planner->plan($settings, $selection, $this->catalog($basePath, $settings, $selection, $inventory), $adoption);
        } catch (PublicationFailed $exception) {
            $this->components->error($exception->getMessage());
            $this->components->error('Nothing was published; existing generated output and afterburner.json were left unchanged.');

            return self::FAILURE;
        }

        foreach ($planner->warnings() as $warning) {
            $this->components->warn($warning);
        }

        $writer = new Writer($basePath);
        $previous = $plan->previous ?? $previous;

        try {
            $writer->apply($plan, $previous);
        } catch (WriteFailed $exception) {
            $configuration->setPublished($writer->owned($previous))->save();

            $this->components->error($exception->getMessage());
            $this->components->error('Publication stopped before completing. afterburner.json records the output Afterburner owns so the next run can replace or remove it; your choices were not saved.');

            return self::FAILURE;
        }

        if ($selection->agentsChosen) {
            $configuration->setAgents($selection->agents);
        }

        if ($selection->baseChosen) {
            $configuration->setBase($selection->base);
        }

        $configuration->setPublished($plan->published)->save();

        $this->reportPublication($plan);

        return self::SUCCESS;
    }

    protected function catalog(string $basePath, Settings $settings, Selection $selection, PackageInventory $inventory): SourceCatalog
    {
        return new SourceCatalog($basePath, $settings, $selection->base, $inventory);
    }

    protected function renderer(string $basePath, Settings $settings, PackageInventory $inventory): Renderer
    {
        return new BladeRenderer($basePath, RenderingContext::assist($basePath, $settings, $inventory));
    }

    protected function reportPublication(Plan $plan): void
    {
        $documents = array_keys($plan->documents);

        $this->components->twoColumnDetail(
            'Guidelines',
            $documents === [] ? 'none' : count($plan->guidelineKeys).' in '.implode(', ', $documents),
        );

        foreach ($plan->skillDestinations as $directory => $names) {
            $this->components->twoColumnDetail('Skills', count($names).' in '.$directory);
        }

        if ($plan->rules !== [] || $plan->ruleRemovals !== []) {
            $this->components->twoColumnDetail('Scoped rules', count($plan->rules).' in .ai/rules/boost');
        }

        $removedSkills = array_sum(array_map('count', $plan->skillRemovals));

        if ($removedSkills > 0 || $plan->documentRemovals !== []) {
            $this->components->twoColumnDetail(
                'Removed',
                trim(($removedSkills > 0 ? "{$removedSkills} stale skill(s) " : '').($plan->documentRemovals !== [] ? 'guidelines from '.implode(', ', array_keys($plan->documentRemovals)) : '')),
            );
        }

        $this->components->info('Afterburner guidelines and skills are up to date.');
    }
}
