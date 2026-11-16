<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\ListOrder;
use App\Repository\EntryQuery;
use App\Repository\TrendingStoryListQuery;
use PHPUnit\Framework\TestCase;

final class TrendingStoryListQueryTest extends TestCase
{
    public function testItListsEveryEntryNewestFirstFromTheFirstPageByDefault(): void
    {
        $query = new TrendingStoryListQuery(userId: 1, storyId: 2);

        self::assertFalse($query->onlyUnread);
        self::assertTrue($query->isFirstPage());
        self::assertSame(ListOrder::NewestFirst, $query->order);
        self::assertSame(EntryQuery::DEFAULT_LIMIT, $query->limit);
    }

    public function testItClampsTheLimit(): void
    {
        self::assertSame(EntryQuery::clampLimit(10_000), new TrendingStoryListQuery(1, 2, limit: 10_000)->limit);
    }
}
