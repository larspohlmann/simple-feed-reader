<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;

/**
 * One page of the for-you feed, as asked for; `EntryQuery` is the main list's. It carries the `User` because the
 * responder needs its recommendation settings; the repository binds `userId()`.
 */
final readonly class ForYouFeedQuery
{
    /** The effective page size — already clamped, never the raw request value. */
    public int $limit;

    public function __construct(
        public User $user,
        public ?string $cursor = null,
        int $limit = EntryQuery::DEFAULT_LIMIT,
        /** Narrow the page to picks the reader has not read yet. */
        public bool $unreadOnly = false,
    ) {
        $this->limit = EntryQuery::clampLimit($limit);
    }

    public function userId(): int
    {
        return $this->user->requireId();
    }
}
