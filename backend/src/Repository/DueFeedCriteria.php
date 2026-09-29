<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Which feeds one query counts as due. $feedId picks one feed, "gone" included; $force skips the schedule but not
 * $cooldownCutoff. $excludedFeedIds keeps a sweep finite: a throttled feed stamps no fetch time, so deriving
 * "handled" from that time spun the client's poll loop (#302).
 */
final readonly class DueFeedCriteria
{
    /**
     * @param list<int> $excludedFeedIds
     */
    public function __construct(
        public \DateTimeImmutable $now,
        public ?int $userId = null,
        public ?int $feedId = null,
        public ?int $tagId = null,
        public bool $force = false,
        public ?\DateTimeImmutable $cooldownCutoff = null,
        public array $excludedFeedIds = [],
    ) {
    }

    /**
     * @param list<int> $feedIds
     */
    public function excluding(array $feedIds): self
    {
        return new self(
            $this->now,
            $this->userId,
            $this->feedId,
            $this->tagId,
            $this->force,
            $this->cooldownCutoff,
            $feedIds,
        );
    }
}
