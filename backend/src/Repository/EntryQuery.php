<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\EntryView;
use App\Enum\ListOrder;
use App\Pagination\EntryCursor;

final readonly class EntryQuery
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 100;

    /**
     * The page size a requested limit actually becomes.
     *
     * Every reader of a keyset-paginated list must clamp identically to the
     * serializer that decides whether the page was full — `EntryPage::of()`
     * compares the row count against the effective size, so a one-off between
     * two spellings makes `nextCursor` vanish on a boundary and the list stop
     * short. It was written five times, in two different orderings; this is
     * that rule, once.
     *
     * `EntryQuery` applies it at construction, so `$query->limit` is ALREADY
     * the effective size. Stays public for `ForYouFeedQuery`, which clamps
     * the ranked feed the same way at ITS construction, so neither pager
     * clamps anything itself.
     */
    public static function clampLimit(int $limit): int
    {
        return max(1, min($limit, self::MAX_LIMIT));
    }

    /** The effective page size — already clamped, never the raw request value. */
    public int $limit;

    /**
     * @param int $limit the size the client asked for
     */
    public function __construct(
        public int $userId,
        public EntryView $view = EntryView::All,
        public ?int $subscriptionId = null,
        public ?int $tagId = null,
        public ?EntryCursor $cursor = null,
        int $limit = self::DEFAULT_LIMIT,
        public ListOrder $order = ListOrder::NewestFirst,
    ) {
        if ($view === EntryView::ForYou) {
            throw new \LogicException('The for-you feed pages through ForYouFeedQuery, never an EntryQuery.');
        }
        $this->limit = self::clampLimit($limit);
    }

    public function ordering(): EntryListOrdering
    {
        return new EntryListOrdering(EntryListSort::forView($this->view), $this->order);
    }

    public function hidesExcludedFeeds(): bool
    {
        if ($this->subscriptionId !== null || $this->tagId !== null) {
            return false;
        }

        return $this->view->isChronological();
    }

    /**
     * A chronological list spanning many feeds, a tag's subset included (#1040, unlike hidesExcludedFeeds())
     * but not one subscription: the only shape that gains from driving the join from `entry` and its
     * effective-date index.
     */
    public function isDateOrderedFanIn(): bool
    {
        if ($this->subscriptionId !== null) {
            return false;
        }

        return $this->view->isChronological();
    }

    /**
     * A fan-in list narrowed to one tag — the shape that can be sparse enough
     * to make the join-order hint a loss rather than a win (#1099).
     */
    public function isTagScopedFanIn(): bool
    {
        return $this->isDateOrderedFanIn() && $this->tagId !== null;
    }
}
