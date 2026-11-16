<?php

declare(strict_types=1);

namespace App\Tests\Dto\Entry;

use App\Dto\Entry\EntryPageParameters;
use App\Enum\ListOrder;
use App\Pagination\EntryCursor;
use PHPUnit\Framework\TestCase;

final class EntryPageParametersTest extends TestCase
{
    public function testMapsTheQueryStringOntoAListPage(): void
    {
        $cursor = EntryCursor::encode(new \DateTimeImmutable('2026-11-16 10:00:00'), 7);

        $page = new EntryPageParameters($cursor, 20, true, 'asc')->toListPage();

        self::assertSame(7, $page->cursor?->id);
        self::assertSame(20, $page->limit);
        self::assertSame(ListOrder::OldestFirst, $page->order);
        self::assertTrue($page->onlyUnread);
    }
}
