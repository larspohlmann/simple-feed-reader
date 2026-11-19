<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky\Model;

/** The record key a post goes out under, and whether an earlier send with the same idempotency key already used it. */
final readonly class BlueskyPostKeyModel
{
    public function __construct(
        public string $recordKey,
        public bool $seenBefore,
    ) {
    }
}
