<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\EntryListPage;
use App\Repository\TrendingStoryListQuery;
use PHPUnit\Framework\TestCase;

final class TrendingStoryListQueryTest extends TestCase
{
    public function testItListsTheDefaultPageUnlessGivenAnother(): void
    {
        self::assertEquals(new EntryListPage(), new TrendingStoryListQuery(userId: 1, storyId: 2)->page);
    }
}
