<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Which feeds one query counts as due for refresh.
 *
 * A parameter object rather than seven arguments repeated across findDue(),
 * countDue() and the builder they share: the scopes only ever travel together,
 * and every caller that named them positionally had to name all of them.
 *
 * $feedId selects exactly one feed, "gone" ones included — that is the manual
 * retry path. $userId restricts to feeds the user subscribes to, and $tagId
 * further restricts to feeds that user tagged with it. $force ignores the
 * schedule but still respects $cooldownCutoff.
 *
 * $excludedFeedIds is what keeps a refresh sweep terminating: the sweep asks
 * "what is still undone?" after it has worked, and a feed it already handled is
 * not undone, whatever the outcome was. Deriving that from the stored fetch
 * time instead is what span the client's poll loop in #302 — a throttled feed
 * never stamps one.
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
