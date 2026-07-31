<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * One row of the account's own subscription list, in the order its owner
 * arranged it (subscription position, not creation time).
 */
final readonly class AdminUserSubscription
{
    /**
     * @param list<AdminSubscriptionTag> $tags this subscription's tags, in the owner's per-tag order
     */
    public function __construct(
        public int $id,
        public ?string $title,
        public ?string $customTitle,
        public string $url,
        public int $position,
        public string $createdAt,
        public ?string $lastFetchedAt,
        public array $tags,
    ) {
    }
}
