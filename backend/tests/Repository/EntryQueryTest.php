<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\EntryQuery;
use PHPUnit\Framework\TestCase;

final class EntryQueryTest extends TestCase
{
    public function testAllAndUnreadWithNoFeedOrTagHideExcludedFeeds(): void
    {
        self::assertTrue((new EntryQuery(1, 'all'))->hidesExcludedFeeds());
        self::assertTrue((new EntryQuery(1, 'unread'))->hidesExcludedFeeds());
    }

    public function testAFeedScopedViewNeverHides(): void
    {
        self::assertFalse((new EntryQuery(1, 'all', subscriptionId: 5))->hidesExcludedFeeds());
        self::assertFalse((new EntryQuery(1, 'unread', subscriptionId: 5))->hidesExcludedFeeds());
    }

    public function testATagScopedViewNeverHides(): void
    {
        self::assertFalse((new EntryQuery(1, 'all', tagId: 3))->hidesExcludedFeeds());
        self::assertFalse((new EntryQuery(1, 'unread', tagId: 3))->hidesExcludedFeeds());
    }

    public function testFavoritesKeptViewedAndForYouNeverHide(): void
    {
        foreach (['favorites', 'kept', 'viewed', 'for-you'] as $view) {
            self::assertFalse((new EntryQuery(1, $view))->hidesExcludedFeeds(), $view);
        }
    }

    public function testAllAndUnreadFanInAcrossFeeds(): void
    {
        self::assertTrue((new EntryQuery(1, 'all'))->isDateOrderedFanIn());
        self::assertTrue((new EntryQuery(1, 'unread'))->isDateOrderedFanIn());
    }

    public function testATagScopedChronologicalViewStillFansIn(): void
    {
        self::assertTrue((new EntryQuery(1, 'all', tagId: 3))->isDateOrderedFanIn());
        self::assertTrue((new EntryQuery(1, 'unread', tagId: 3))->isDateOrderedFanIn());
    }

    public function testASingleSubscriptionScopeNeverFansIn(): void
    {
        self::assertFalse((new EntryQuery(1, 'all', subscriptionId: 5))->isDateOrderedFanIn());
        self::assertFalse((new EntryQuery(1, 'unread', subscriptionId: 5))->isDateOrderedFanIn());
    }

    public function testStateDrivenViewsNeverFanIn(): void
    {
        foreach (['favorites', 'kept', 'viewed', 'for-you'] as $view) {
            self::assertFalse((new EntryQuery(1, $view))->isDateOrderedFanIn(), $view);
        }
    }
}
