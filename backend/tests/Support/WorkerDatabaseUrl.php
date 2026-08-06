<?php

declare(strict_types=1);

namespace App\Tests\Support;

use function str_starts_with;
use function strrpos;
use function substr_replace;

/**
 * Gives a parallel test worker a database file of its own.
 *
 * Infection (like ParaTest) runs each worker with a TEST_TOKEN, and
 * doctrine.yaml turns that token into a dbname suffix. That isolates the MySQL
 * leg, but a SQLite DSN carries a file path rather than a database name, so the
 * suffix never reaches it: every worker would open — and tests/bootstrap.php
 * would delete — the same file. Deleting a database out from under the sibling
 * workers fails their tests, and a mutation run reads a failing test as a
 * killed mutant, so the score comes out high for the wrong reason.
 *
 * Putting the token in the file name closes that gap. The token is the worker
 * index, so the file count is bounded by the thread count, not by the number of
 * mutants.
 */
final readonly class WorkerDatabaseUrl
{
    private const string SQLITE_SCHEME = 'sqlite:///';

    public static function forWorker(string $databaseUrl, string $workerToken): string
    {
        if ($workerToken === '' || !str_starts_with($databaseUrl, self::SQLITE_SCHEME)) {
            return $databaseUrl;
        }

        $lastSeparator = strrpos($databaseUrl, '/');
        $extensionDot = strrpos($databaseUrl, '.');

        // A DSN without a file extension is `:memory:`, which every process
        // already gets its own copy of. Nothing to isolate.
        if ($extensionDot === false || $lastSeparator === false || $extensionDot < $lastSeparator) {
            return $databaseUrl;
        }

        return substr_replace($databaseUrl, $workerToken, $extensionDot, 0);
    }
}
