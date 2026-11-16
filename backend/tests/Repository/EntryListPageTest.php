<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\ListOrder;
use App\Pagination\EntryCursor;
use App\Repository\EntryListOrdering;
use App\Repository\EntryListPage;
use App\Repository\EntryQuery;
use PHPUnit\Framework\TestCase;

final class EntryListPageTest extends TestCase
{
    public function testClampsTheLimitToTheMaximum(): void
    {
        self::assertSame(EntryQuery::clampLimit(10_000), new EntryListPage(limit: 10_000)->limit);
    }

    public function testOrdersByPublishedDateInItsOrder(): void
    {
        $page = new EntryListPage(order: ListOrder::OldestFirst);

        self::assertEquals(EntryListOrdering::byPublishedDate(ListOrder::OldestFirst), $page->ordering());
    }

    public function testIsTheFirstPageOnlyWithoutACursor(): void
    {
        $cursor = new EntryCursor(new \DateTimeImmutable('2026-11-16 10:00:00'), 7);

        self::assertTrue(new EntryListPage()->isFirstPage());
        self::assertFalse(new EntryListPage(cursor: $cursor)->isFirstPage());
    }
}
