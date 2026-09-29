<?php

declare(strict_types=1);

namespace App\Tests\Support;

use function str_starts_with;
use function strrpos;
use function substr_replace;

/**
 * Puts TEST_TOKEN into a SQLite file name: doctrine.yaml's dbname suffix never reaches a file path, so parallel
 * workers would share one file that tests/bootstrap.php deletes, and Infection would score the failures as kills.
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
