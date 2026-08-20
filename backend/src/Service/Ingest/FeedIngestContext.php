<?php

declare(strict_types=1);

namespace App\Service\Ingest;

/**
 * What one ingest pass knows about the fetch it is storing: when the run
 * started, and when this feed last SUCCEEDED before it.
 *
 * Both values belong to the pass, not to any single entry, and every entry the
 * pass stores needs both. Passing them one call at a time would thread two
 * parameters through the ingest chain that nothing in between reads — the tramp
 * data `composer tramp` fails the build over. They have a home here instead.
 *
 * A null previousFetchAt means this pass gets first-fetch treatment, which is
 * correct in two cases: nobody has fetched this feed yet (the subscribe that
 * seeds a new feed, or a refresh of a feed whose seeding never happened), or
 * every fetch attempted so far has failed. Either way nothing is known about
 * what the feed was serving before, so its articles keep their own published
 * dates rather than being measured against a fetch that never delivered.
 */
final readonly class FeedIngestContext
{
    public function __construct(
        public \DateTimeImmutable $fetchedAt,
        public ?\DateTimeImmutable $previousFetchAt,
    ) {
    }
}
