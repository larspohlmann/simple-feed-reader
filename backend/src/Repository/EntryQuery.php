<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EntryCursor;

final readonly class EntryQuery
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;

    /** @param 'all'|'unread'|'favorites'|'kept' $view */
    public function __construct(
        public int $userId,
        public string $view = 'all',
        public ?int $subscriptionId = null,
        public ?int $tagId = null,
        public ?EntryCursor $cursor = null,
        public int $limit = self::DEFAULT_LIMIT,
    ) {
    }
}
