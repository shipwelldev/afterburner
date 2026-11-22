<?php

declare(strict_types=1);

namespace Shipwell\Afterburner\Database;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

/**
 * Runs a query inside a database-enforced read-only transaction.
 *
 * MySQL and MariaDB use `SET TRANSACTION READ ONLY` for the next transaction,
 * PostgreSQL sets the current transaction read-only, and SQLite enables
 * `PRAGMA query_only` for the duration of the query and then restores the
 * connection's previous setting. The transaction is always rolled back.
 */
final class ReadOnlyExecutor
{
    public const SUPPORTED_DRIVERS = ['sqlite', 'mysql', 'mariadb', 'pgsql'];

    /**
     * @return array<int, mixed>
     *
     * @throws Throwable
     */
    public static function select(ConnectionInterface $connection, string $query): array
    {
        $driver = $connection->getDriverName();

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new RuntimeException("Read-only execution is not supported for the [{$driver}] driver. Supported drivers: ".implode(', ', self::SUPPORTED_DRIVERS).'.');
        }

        $previousQueryOnly = null;

        if ($driver === 'sqlite') {
            $previousQueryOnly = (int) ($connection->selectOne('PRAGMA query_only')?->query_only ?? 0);
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // MySQL and MariaDB only honor READ ONLY when it is set before the transaction starts.
            $connection->statement('SET TRANSACTION READ ONLY');
        }

        $connection->beginTransaction();

        try {
            if ($driver === 'pgsql') {
                $connection->statement('SET TRANSACTION READ ONLY');
            } elseif ($driver === 'sqlite') {
                $connection->statement('PRAGMA query_only = ON');
            }

            return $connection->select($query);
        } finally {
            try {
                $connection->rollBack();
            } finally {
                if ($previousQueryOnly !== null) {
                    $connection->statement('PRAGMA query_only = '.($previousQueryOnly === 1 ? 'ON' : 'OFF'));
                }
            }
        }
    }
}
