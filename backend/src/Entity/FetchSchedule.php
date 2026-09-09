<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A feed's fetch-schedule state: when it was last asked, when it last
 * actually delivered, when it last brought back new entries, when it may be
 * asked again, and the failure streak and message behind that schedule.
 *
 * Embedded into Feed rather than left as seven of its own scalar columns —
 * PHPMD's field-count ceiling on Feed is a proxy for a real seam: these seven
 * values are read and written together, by FeedScheduler alone, and belong
 * to the same concern. An embeddable keeps them there without the join or
 * lifecycle a separate entity would add; the column names are unprefixed so
 * the table itself is unchanged.
 */
#[ORM\Embeddable]
class FetchSchedule
{
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    /**
     * When a fetch last actually delivered — as opposed to lastFetchedAt,
     * which also advances on a failed or gone attempt (FeedScheduler::
     * recordFailure(), recordGone()). Null means every fetch of this feed has
     * failed so far.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSuccessfulFetchAt = null;

    /**
     * When a fetch last brought back new entries — as opposed to
     * lastSuccessfulFetchAt, which advances on every 200 even when the feed
     * carried nothing new. This is the "last updated" the reader shows. Null
     * until the first fetch that adds an entry.
     */
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

    public function setLastFetchedAt(?\DateTimeImmutable $lastFetchedAt): void
    {
        $this->lastFetchedAt = $lastFetchedAt;
    }

    public function getLastSuccessfulFetchAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessfulFetchAt;
    }

    public function setLastSuccessfulFetchAt(?\DateTimeImmutable $lastSuccessfulFetchAt): void
    {
        $this->lastSuccessfulFetchAt = $lastSuccessfulFetchAt;
    }

    public function getLastNewEntryAt(): ?\DateTimeImmutable
    {
        return $this->lastNewEntryAt;
    }

    public function setLastNewEntryAt(?\DateTimeImmutable $lastNewEntryAt): void
    {
        $this->lastNewEntryAt = $lastNewEntryAt;
    }

    public function getNextFetchAt(): ?\DateTimeImmutable
    {
        return $this->nextFetchAt;
    }

    public function setNextFetchAt(?\DateTimeImmutable $nextFetchAt): void
    {
        $this->nextFetchAt = $nextFetchAt;
    }

    public function getFetchIntervalMinutes(): int
    {
        return $this->fetchIntervalMinutes;
    }

    public function setFetchIntervalMinutes(int $fetchIntervalMinutes): void
    {
        $this->fetchIntervalMinutes = $fetchIntervalMinutes;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function setConsecutiveFailures(int $consecutiveFailures): void
    {
        $this->consecutiveFailures = $consecutiveFailures;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function setLastErrorMessage(?string $lastErrorMessage): void
    {
        $this->lastErrorMessage = $lastErrorMessage;
    }
}
