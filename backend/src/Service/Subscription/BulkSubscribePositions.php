<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Tag;

/** Where a batch's next rows go: nothing flushes until the end, so MAX(position) cannot see this batch's rows. */
final class BulkSubscribePositions
{
    /** @var array<int, int> keyed by spl_object_id(tag): a tag created in this batch has no id yet */
    private array $nextFeedPositionByTag = [];

    public function __construct(
        private int $nextSubscriptionPosition,
        private int $nextTagPosition,
    ) {
    }

    public function takeSubscriptionPosition(): int
    {
        return $this->nextSubscriptionPosition++;
    }

    public function takeTagPosition(): int
    {
        return $this->nextTagPosition++;
    }

    /**
     * @param \Closure(): int $firstFreePosition asked once per tag, on the tag's first use in this batch
     */
    public function takeFeedPositionIn(Tag $tag, \Closure $firstFreePosition): int
    {
        $key = spl_object_id($tag);
        $this->nextFeedPositionByTag[$key] ??= $firstFreePosition();

        return $this->nextFeedPositionByTag[$key]++;
    }
}
