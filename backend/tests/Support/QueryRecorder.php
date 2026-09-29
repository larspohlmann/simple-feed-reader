<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Records every SQL statement run, so a test can count queries: an N+1 returns the same body as one batched read.
 * Fetch it after the request you measure: each request after a client's first reboots the kernel, whose connection
 * reports to a new recorder, so one fetched earlier records nothing.
 */
final class QueryRecorder implements Middleware
{
    /**
     * DoctrineBundle builds the connection with a per-connection clone registered under this id; the plain class id is
     * an unwired instance that records nothing.
     */
    public const SERVICE_ID = self::class . '.default';

    /** @var list<string> */
    private array $queries = [];

    public function wrap(Driver $driver): Driver
    {
        return new QueryRecorderDriver($driver, $this);
    }

    public function record(string $sql): void
    {
        $this->queries[] = $sql;
    }

    public function reset(): void
    {
        $this->queries = [];
    }

    /**
     * Every statement executed since the last reset.
     *
     * @return list<string>
     */
    public function queries(): array
    {
        return $this->queries;
    }

    /** @return list<string> */
    public function queriesMatching(string $needle): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (string $sql): bool => str_contains(strtolower($sql), strtolower($needle)),
        ));
    }
}
