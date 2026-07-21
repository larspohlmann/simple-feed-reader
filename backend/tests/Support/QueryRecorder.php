<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * A DBAL middleware that records the SQL a request actually executed.
 *
 * Exists so "this is one query, not one per row" can be **counted** rather than
 * read off the source. An N+1 is invisible to an assertion on the response body
 * — the payload is identical either way — so the only test that can fail when
 * somebody replaces the batched lookup with a loop is one that looks at the
 * wire.
 *
 * Registered as a `doctrine.middleware` in `config/services_test.yaml`, test
 * environment only. It is deliberately not a logger: it holds strings in
 * memory, is cleared per case, and never sees bound parameter values, so no
 * credential or token can reach it.
 */
final class QueryRecorder implements Middleware
{
    /**
     * The id a test must fetch — note the suffix.
     *
     * DoctrineBundle clones every `doctrine.middleware` service once per
     * connection and appends the connection name, and it is the clone the
     * connection is built with. Fetching the plain class id hands you a
     * second, freshly constructed instance that is wired to nothing and
     * reports zero queries forever.
     *
     * That fails loudly rather than silently — the guard asserts an exact count
     * of one, so zero is red, not green — but it fails as "the batched read
     * vanished" when the truth is "you fetched the wrong object", and those two
     * send you to opposite ends of the codebase. This cost an hour; it is a
     * constant so it cannot cost another.
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

    /**
     * The subset touching one table, matched case-insensitively on a bare
     * substring — good enough to separate `user_identity` reads from the rest
     * of a request, and it does not need to parse SQL to do it.
     *
     * @return list<string>
     */
    public function queriesMatching(string $needle): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (string $sql): bool => str_contains(strtolower($sql), strtolower($needle)),
        ));
    }
}
