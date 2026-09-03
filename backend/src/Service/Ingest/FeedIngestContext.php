<?php

declare(strict_types=1);

namespace App\Service\Ingest;

/**
 * What one ingest pass knows about the fetch it is storing: when the run
 * started, and when this feed last SUCCEEDED before it.
 *
 * Both values belong to the pass, not any single entry, yet every stored
 * entry needs both. Threading them per-call would create tramp data
 * `composer tramp` fails the build over, so they live here instead.
 *
 * A null previousFetchAt means first-fetch treatment: nobody has fetched this
 * feed yet (fresh subscribe, or a refresh whose seeding never happened), or
 * every attempt so far failed. Either way nothing is known about what the
 * feed served, so articles keep their own published dates.
 */
final readonly class FeedIngestContext
{
    public function __construct(
        public \DateTimeImmutable $fetchedAt,
        public ?\DateTimeImmutable $previousFetchAt,
    ) {
    }
}
