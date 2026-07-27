<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Tag;

/**
 * Mutable bookkeeping for ONE batch. Not a domain concept — it exists so the
 * batch-local identity maps and position counters travel together instead of as
 * six by-reference parameters.
 *
 * @internal to BulkSubscriber
 */
final class BulkSubscribeState
{
    /** @var array<string, Tag> keyed by lowercased tag name */
    public array $tagCache = [];

    /** @var array<string, true> feed URLs subscribed during this batch */
    public array $seen = [];

    /** @var array<int, int> keyed by spl_object_id(tag) */
    public array $nextFeedPositionInTag = [];

    public function __construct(
        public int $existing,
        public int $nextSubscriptionPosition,
        public int $nextTagPosition,
    ) {
    }
}
