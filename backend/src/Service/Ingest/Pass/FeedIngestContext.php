<?php

declare(strict_types=1);

namespace App\Service\Ingest\Pass;

/**
 * One ingest pass's run instant and this feed's previous SUCCESSFUL fetch. A null previousFetchAt (never fetched,
 * or every attempt failed) means first-fetch treatment: articles keep their own published dates.
 */
final readonly class FeedIngestContext
{
    public function __construct(
        public \DateTimeImmutable $fetchedAt,
        public ?\DateTimeImmutable $previousFetchAt,
    ) {
    }
}
