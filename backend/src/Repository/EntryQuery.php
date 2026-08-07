<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EntryCursor;

final readonly class EntryQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 100;

    /**
     * @param int                                         $userId
     * @param 'all'|'unread'|'favorites'|'kept'|'for-you' $view
     * @param int|null                                    $subscriptionId
     * @param int|null                                    $tagId
     * @param EntryCursor|null                             $cursor
     * @param int                                         $limit
     */
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
