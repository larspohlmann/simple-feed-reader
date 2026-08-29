<?php

declare(strict_types=1);

namespace App\Repository;

use App\Http\EntryCursor;

final readonly class EntryQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 100;

    /**
     * The page size a requested limit actually becomes.
     *
     * Every reader of a keyset-paginated list must clamp identically to the
     * serializer that decides whether the page was full — `EntryPage::of()`
     * compares the row count against the effective size, so a difference of
     * one between two spellings makes `nextCursor` vanish on a boundary and
     * the list stop short. It was written five times across the repositories
     * and the serializer, in two different orderings; this is that rule, once.
     *
     * `EntryQuery` applies it at construction, so `$query->limit` is ALREADY
     * the effective size and readers pass it straight on. This stays public
     * for `ForYouFeedQuery`, which is the same idea for the ranked feed and
     * clamps the same way at ITS construction — so the for-you pager and
     * repository no longer clamp anything themselves either.
     */
    public static function clampLimit(int $limit): int
    {
        return max(1, min($limit, self::MAX_LIMIT));
    }

    /** The effective page size — already clamped, never the raw request value. */
    public int $limit;

    /**
     * @param int                                                  $userId
     * @param 'all'|'unread'|'favorites'|'kept'|'viewed'|'for-you' $view
     * @param int|null                                             $subscriptionId
     * @param int|null                                             $tagId
     * @param EntryCursor|null                                     $cursor
     * @param int                                                  $limit the size the client asked for
     */
    public function __construct(
        public int $userId,
        public string $view = 'all',
        public ?int $subscriptionId = null,
        public ?int $tagId = null,
        public ?EntryCursor $cursor = null,
        int $limit = self::DEFAULT_LIMIT,
    ) {
        $this->limit = self::clampLimit($limit);
    }

    public function hidesExcludedFeeds(): bool
    {
        if ($this->subscriptionId !== null || $this->tagId !== null) {
            return false;
        }

        return $this->view === 'all' || $this->view === 'unread';
    }
}
