<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A drag of one feed between the sidebar's lists: out of {@see $fromTagId} and
 * into {@see $toTagId} at {@see $position}. A null tag id is the untagged
 * "Feeds" list; a null position appends.
 */
final readonly class MoveFeedToTagRequest
{
    public function __construct(
        #[Assert\Positive]
        public ?int $fromTagId = null,
        #[Assert\Positive]
        public ?int $toTagId = null,
        #[Assert\PositiveOrZero]
        public ?int $position = null,
    ) {
    }
}
