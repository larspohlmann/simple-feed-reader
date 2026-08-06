<?php

declare(strict_types=1);

namespace App\Tests\Support;

use function putenv;

/**
 * Keeps parallel test workers out of each other's state.
 *
 * A parallel runner (Infection, ParaTest) gives every worker a TEST_TOKEN.
 * Without one, this does nothing at all: a serial `php bin/phpunit` run keeps
 * the plain, inspectable database file and cache directory it always had.
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

        // The cache pools are shared state too, and the rate limiter keeps its
        // sliding windows in one of them: without this a worker spends the
        // budget its siblings were about to assert on, and their tests fail
        // with a 429 that has nothing to do with the code under test. The
        // directory is the lever rather than prefix_seed, because pool
        // namespaces are computed when the container is compiled and the
        // workers share one compiled container.
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
