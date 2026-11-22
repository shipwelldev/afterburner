<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Console;

use Illuminate\Console\Command;
use Shipwell\Afterburner\Rules\RuleFile;
use Shipwell\Afterburner\Rules\RuleIndex;

class IndexRulesCommand extends Command
{
    /** @var string */
    protected $signature = 'afterburner:index-rules';

    /** @var string */
    protected $description = 'Validate the project rules in .ai/rules and regenerate their index';

    public function handle(): int
    {
        $index = new RuleIndex($this->laravel->basePath());

        if (! $index->exists()) {
            $this->components->info('No project rules found in .ai/rules.');

            return self::SUCCESS;
        }

        $files = $index->files();
        $conflicted = array_filter($files, fn (RuleFile $file): bool => $file->conflicted);

        if ($conflicted !== []) {
            $this->components->error('Resolve the merge conflicts in these rule files first; the index was not changed:');
            $this->components->bulletList(array_map(fn (RuleFile $file): string => $file->relative, $conflicted));

            return self::FAILURE;
        }

        foreach ($files as $file) {
            if (! $file->indexable()) {
                $this->components->warn("Skipped {$file->relative}: {$file->problem}. It is not in the index.");
            }
        }

        if (@file_put_contents($index->path(RuleIndex::INDEX), RuleIndex::render($files)) === false) {
            $this->components->error('Unable to write '.RuleIndex::INDEX.'.');

            return self::FAILURE;
        }

        $indexed = count(array_filter($files, fn (RuleFile $file): bool => $file->indexable()));

        $this->components->info('Regenerated '.RuleIndex::INDEX." ({$indexed} ".($indexed === 1 ? 'rule file' : 'rule files').').');

        return self::SUCCESS;
    }
}
