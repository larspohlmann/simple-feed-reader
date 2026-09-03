<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Which feeds one query counts as due for refresh.
 *
 * A parameter object, not seven positional arguments shared by findDue(),
 * countDue() and their builder — the scopes always travel together.
 *
 * $feedId selects one feed, "gone" ones included (the manual retry path).
 * $userId scopes to the user's feeds, $tagId further to one tag. $force
 * ignores the schedule but still respects $cooldownCutoff.
 *
 * $excludedFeedIds keeps a refresh sweep terminating: a feed already handled
 * must not count as still undone. Deriving that from the stored fetch time
 * instead spun the client's poll loop in #302 — a throttled feed never
 * stamps one.
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
