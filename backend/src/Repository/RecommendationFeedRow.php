<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * One row of the for-you feed: the shared EntryListRow projection (identical
 * to the main list's, so it hydrates the same way) plus the run-specific bits
 * the main list has no concept of.
 */
final readonly class RecommendationFeedRow
{
    public function __construct(
        public EntryListRow $row,
        public string $reason,
        public int $runId,
        public int $position,
        public ?int $score,
        public ?\DateTimeImmutable $runGeneratedAt = null,
    ) {
    }
}
