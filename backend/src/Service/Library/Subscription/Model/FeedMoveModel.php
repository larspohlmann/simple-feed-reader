<?php

declare(strict_types=1);

namespace App\Service\Library\Subscription\Model;

/** A drag between the sidebar's lists. A null tag id is the untagged "Feeds" list; a null position appends. */
final readonly class FeedMoveModel
{
    public function __construct(
        public ?int $fromTagId = null,
        public ?int $toTagId = null,
        public ?int $position = null,
    ) {
    }
}
