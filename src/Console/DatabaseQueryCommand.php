<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use JsonException;
use Shipwell\Afterburner\Database\ReadOnlyExecutor;
use Shipwell\Afterburner\Database\ReadOnlyQuery;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class DatabaseQueryCommand extends Command
{
    /** @var string */
    protected $signature = 'afterburner:database:query
        {query : The read-only SQL query to execute}
        {--connection= : The database connection to use (defaults to the application default)}';

    /** @var string */
    protected $description = 'Execute a read-only SQL query and print the rows as JSON';

    public function handle(DatabaseManager $database): int
    {
        $query = trim((string) $this->argument('query'));

        if ($query === '') {
            return $this->reject('Pass a SQL query to execute.');
        }

        $name = $this->option('connection');
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;

        try {
            // Resolving a connection does not connect to it; validation needs only its driver.
            $connection = $database->connection($name);
        } catch (Throwable $exception) {
            return $this->reject('Query failed: '.$exception->getMessage());
        }

        $driver = $connection->getDriverName();

        if (($rejection = ReadOnlyQuery::rejection($query, $driver)) !== null) {
            return $this->reject('Query rejected: '.$rejection.' Nothing was executed.');
        }

        try {
            $prefix = $connection->getTablePrefix();

            if ($prefix !== '') {
                $query = ReadOnlyQuery::prefixed($query, $prefix, $driver);
            }

            $rows = ReadOnlyExecutor::select($connection, $query);
        } catch (Throwable $exception) {
            return $this->reject('Query failed: '.$exception->getMessage());
        }

        try {
            $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->reject('The result cannot be represented as JSON ('.$exception->getMessage().'). Select binary columns through an encoding function such as hex().');
        }

        $this->output->writeln($json, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * Report a failure on stderr so stdout only ever carries a JSON result.
     */
    protected function reject(string $message): int
    {
        $output = $this->output->getOutput();
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $errors->writeln('<error>'.OutputFormatter::escape($message).'</error>');

        return self::FAILURE;
    }
}
