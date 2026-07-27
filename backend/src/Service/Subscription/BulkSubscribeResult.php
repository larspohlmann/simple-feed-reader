<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Tag;

final readonly class BulkSubscribeResult
{
    /**
     * @param list<Tag> $tagsCreated tags this batch brought into being, not ones it reused
     */
    public function __construct(
        public int $imported = 0,
        public int $alreadySubscribed = 0,
        public int $invalid = 0,
        public int $skippedOverLimit = 0,
        public array $tagsCreated = [],
    ) {
    }

    /**
     * @param list<Tag> $tagsCreated
     */
    public function with(
        int $imported = 0,
        int $alreadySubscribed = 0,
        int $invalid = 0,
        int $skippedOverLimit = 0,
        array $tagsCreated = [],
    ): self {
        return new self(
            $this->imported + $imported,
            $this->alreadySubscribed + $alreadySubscribed,
            $this->invalid + $invalid,
            $this->skippedOverLimit + $skippedOverLimit,
            [...$this->tagsCreated, ...$tagsCreated],
        );
    }
}
