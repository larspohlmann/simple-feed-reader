<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;

/**
 * One page of the for-you feed, as asked for. `EntryQuery` is the same idea for
 * the main list: the whole request travels as one value, so the responder, the
 * pager and the repository each read what they need off it instead of
 * forwarding four scalars none of them looks at (phptramp, and the reason
 * `EntryQuery` exists at all).
 *
 * It carries the `User` rather than an id because the responder needs the
 * entity for the caller's recommendation settings; the repository binds
 * `userId()`, the way every other query in here does.
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
        return (int) $this->user->getId();
    }
}
