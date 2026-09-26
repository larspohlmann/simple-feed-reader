<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A feed's fetch-schedule state, embedded into Feed with unprefixed columns so the table is unchanged.
 */
#[ORM\Embeddable]
class FetchSchedule
{
    private const int ERROR_MESSAGE_MAX = 1000;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    /** When a fetch last delivered; unlike lastFetchedAt, a failed or gone attempt leaves it alone. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSuccessfulFetchAt = null;

    /** When a fetch last brought new entries, the reader's "last updated"; an empty 200 leaves it alone. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastNewEntryAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextFetchAt = null;

    #[ORM\Column]
    private int $fetchIntervalMinutes = 60;

    #[ORM\Column]
    private int $consecutiveFailures = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastErrorMessage = null;

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function getLastSuccessfulFetchAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessfulFetchAt;
    }

    public function getLastNewEntryAt(): ?\DateTimeImmutable
    {
        return $this->lastNewEntryAt;
    }

    public function getNextFetchAt(): ?\DateTimeImmutable
    {
        return $this->nextFetchAt;
    }

    public function getFetchIntervalMinutes(): int
    {
        return $this->fetchIntervalMinutes;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function recordSuccess(\DateTimeImmutable $fetchedAt, int $intervalMinutes): void
    {
        $this->fetchIntervalMinutes = $intervalMinutes;
        $this->consecutiveFailures = 0;
        $this->lastErrorMessage = null;
        $this->lastFetchedAt = $fetchedAt;
        $this->lastSuccessfulFetchAt = $fetchedAt;
        $this->nextFetchAt = $fetchedAt->modify(sprintf('+%d minutes', $intervalMinutes));
    }

    public function recordNewEntries(\DateTimeImmutable $arrivedAt): void
    {
        $this->lastNewEntryAt = $arrivedAt;
    }

    /**
     * @throws \DateMalformedStringException
     */
    public function recordFailure(\DateTimeImmutable $failedAt, string $message, int $backoffMinutes): void
    {
        $this->countFailedAttempt($failedAt, $message);
        $this->nextFetchAt = $failedAt->modify(sprintf('+%d minutes', $backoffMinutes));
    }

    public function recordGone(\DateTimeImmutable $failedAt, string $message): void
    {
        $this->countFailedAttempt($failedAt, $message);
        $this->nextFetchAt = null;
    }

    public function scheduleNextFetchAt(\DateTimeImmutable $nextFetchAt): void
    {
        $this->nextFetchAt = $nextFetchAt;
    }

    private function countFailedAttempt(\DateTimeImmutable $failedAt, string $message): void
    {
        ++$this->consecutiveFailures;
        $this->lastErrorMessage = mb_substr($message, 0, self::ERROR_MESSAGE_MAX);
        $this->lastFetchedAt = $failedAt;
    }
}
