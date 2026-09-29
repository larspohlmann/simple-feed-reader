<?php

declare(strict_types=1);

namespace App\Tests\Support;

use function putenv;

/**
 * Keeps parallel test workers (Infection, ParaTest: one TEST_TOKEN each) out of each other's state. Without a token it
 * does nothing, so a serial run keeps the plain database file and cache directory.
 */
final readonly class WorkerIsolation
{
    public static function applyToEnvironment(): void
    {
        $workerToken = self::read('TEST_TOKEN');

        if ($workerToken === '') {
            return;
        }

        self::write(
            'DATABASE_URL',
            WorkerDatabaseUrl::forWorker(self::read('DATABASE_URL'), $workerToken),
        );

        // The rate limiter keeps its windows in the cache pools, so each worker gets its own directory. The directory,
        // not prefix_seed: pool namespaces are fixed when the one shared container is compiled.
        self::write('CACHE_DIRECTORY', self::read('CACHE_DIRECTORY') . $workerToken);
    }

    private static function read(string $name): string
    {
        $value = $_SERVER[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Symfony reads configuration from $_SERVER/$_ENV, but the console commands
     * the bootstrap shells out to are child processes that read the real
     * environment, so a value has to reach all three.
     */
    private static function write(string $name, string $value): void
    {
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
    }
}
