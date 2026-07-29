<?php

declare(strict_types=1);

namespace App\Service\Clock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Symfony\Component\Clock\ClockInterface;

/**
 * A clock whose "now" is the database server's UTC time rather than the PHP
 * process clock. The refresh pipeline reads it so a skewed process clock — the
 * FastCGI web tier was observed running ~1 h fast — can never stamp a feed's
 * fetch time or an entry's ingest time in the future and freeze later refreshes
 * out (see FeedRepository::dueQueryBuilder). One authoritative clock, shared by
 * every writer, keeps persisted refresh timestamps monotonic and truthful.
 */
final readonly class DatabaseClock implements ClockInterface
{
    private \DateTimeZone $timeZone;

    /**
     * @throws \DateInvalidTimeZoneException
     */
    public function __construct(
        private Connection $connection,
        \DateTimeZone|string $timeZone = 'UTC',
    ) {
        $this->timeZone = \is_string($timeZone) ? new \DateTimeZone($timeZone) : $timeZone;
    }

    /**
     * @throws \DateMalformedStringException
     * @throws Exception
     */
    public function now(): \DateTimeImmutable
    {
        $utc = new \DateTimeImmutable($this->readDatabaseUtc(), new \DateTimeZone('UTC'));

        return $utc->setTimezone($this->timeZone);
    }

    public function sleep(float|int $seconds): void
    {
        // A delay is relative, so it never needs the trusted wall clock: sleeping
        // locally avoids paying a round trip for something the process can time.
        usleep((int) round($seconds * 1_000_000));
    }

    /**
     * @throws \DateInvalidTimeZoneException
     */
    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        return new self($this->connection, $timezone);
    }

    /**
     * @throws Exception
     */
    private function readDatabaseUtc(): string
    {
        // MySQL's CURRENT_TIMESTAMP is session-local (the production DB session is
        // CEST); UTC_TIMESTAMP() is not. SQLite's CURRENT_TIMESTAMP is already UTC.
        $expression = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? 'UTC_TIMESTAMP()'
            : 'CURRENT_TIMESTAMP';

        $now = $this->connection->fetchOne('SELECT ' . $expression);
        if (!\is_string($now)) {
            // Casting instead would turn a missing value into '' — which
            // DateTimeImmutable reads as the PHP clock's "now", silently
            // reintroducing the very skew this class exists to remove.
            throw new \RuntimeException('The database returned no current timestamp.');
        }

        return $now;
    }
}
