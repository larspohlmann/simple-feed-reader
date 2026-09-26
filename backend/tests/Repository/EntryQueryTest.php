<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\EntryView;
use App\Enum\ListOrder;
use App\Repository\EntryListSort;
use App\Repository\EntryQuery;
use PHPUnit\Framework\TestCase;

final class EntryQueryTest extends TestCase
{
    public function testAllAndUnreadWithNoFeedOrTagHideExcludedFeeds(): void
    {
        self::assertTrue((new EntryQuery(1, EntryView::All))->hidesExcludedFeeds());
        self::assertTrue((new EntryQuery(1, EntryView::Unread))->hidesExcludedFeeds());
    }

    public function testAFeedScopedViewNeverHides(): void
    {
        self::assertFalse((new EntryQuery(1, EntryView::All, subscriptionId: 5))->hidesExcludedFeeds());
        self::assertFalse((new EntryQuery(1, EntryView::Unread, subscriptionId: 5))->hidesExcludedFeeds());
    }

    public function testATagScopedViewNeverHides(): void
    {
        self::assertFalse((new EntryQuery(1, EntryView::All, tagId: 3))->hidesExcludedFeeds());
        self::assertFalse((new EntryQuery(1, EntryView::Unread, tagId: 3))->hidesExcludedFeeds());
    }

    public function testFavoritesKeptAndViewedNeverHide(): void
    {
        foreach ([EntryView::Favorites, EntryView::Kept, EntryView::Viewed] as $view) {
            self::assertFalse((new EntryQuery(1, $view))->hidesExcludedFeeds(), $view->value);
        }
    }

    public function testAllAndUnreadFanInAcrossFeeds(): void
    {
        self::assertTrue((new EntryQuery(1, EntryView::All))->isDateOrderedFanIn());
        self::assertTrue((new EntryQuery(1, EntryView::Unread))->isDateOrderedFanIn());
    }

    public function testATagScopedChronologicalViewStillFansIn(): void
    {
        self::assertTrue((new EntryQuery(1, EntryView::All, tagId: 3))->isDateOrderedFanIn());
        self::assertTrue((new EntryQuery(1, EntryView::Unread, tagId: 3))->isDateOrderedFanIn());
    }

    public function testASingleSubscriptionScopeNeverFansIn(): void
    {
        self::assertFalse((new EntryQuery(1, EntryView::All, subscriptionId: 5))->isDateOrderedFanIn());
        self::assertFalse((new EntryQuery(1, EntryView::Unread, subscriptionId: 5))->isDateOrderedFanIn());
    }

    public function testStateDrivenViewsNeverFanIn(): void
    {
        foreach ([EntryView::Favorites, EntryView::Kept, EntryView::Viewed] as $view) {
            self::assertFalse((new EntryQuery(1, $view))->isDateOrderedFanIn(), $view->value);
        }
    }

    public function testTheForYouFeedIsNotAnEntryListQuery(): void
    {
        $this->expectException(\LogicException::class);

        new EntryQuery(1, EntryView::ForYou);
    }

    public function testTheOrderingPairsTheViewsSortWithTheRequestedOrder(): void
    {
        $viewed = (new EntryQuery(1, EntryView::Viewed, order: ListOrder::OldestFirst))->ordering();
        self::assertSame(EntryListSort::ViewedAt, $viewed->sort);
        self::assertSame(ListOrder::OldestFirst, $viewed->order);

        $all = (new EntryQuery(1))->ordering();
        self::assertSame(EntryListSort::PublishedDate, $all->sort);
        self::assertSame(ListOrder::NewestFirst, $all->order);
    }
}
