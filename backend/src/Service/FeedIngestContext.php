<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What one ingest pass knows about the fetch it is storing: when the run
 * started, and when we last fetched this feed before it.
 *
 * Both values belong to the pass, not to any single entry, and every entry the
 * pass stores needs both. Passing them one call at a time would thread two
 * parameters through the ingest chain that nothing in between reads — the tramp
 * data `composer tramp` fails the build over. They have a home here instead.
 *
 * A null previousFetchAt means nobody has fetched this feed yet, so this pass
 * is its first: the subscribe that seeds a new feed, and equally a refresh of a
 * feed whose seeding never happened.
 */
final readonly class FeedIngestContext
{
    public function __construct(
        public \DateTimeImmutable $fetchedAt,
        public ?\DateTimeImmutable $previousFetchAt,
    ) {
    }
}
