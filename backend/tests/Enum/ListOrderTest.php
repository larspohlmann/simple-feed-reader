<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\ListOrder;
use App\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ListOrderTest extends TestCase
{
    public function testAnAbsentOrEmptyValueIsNewestFirst(): void
    {
        self::assertSame(ListOrder::NewestFirst, ListOrder::fromRequestValue(null));
        self::assertSame(ListOrder::NewestFirst, ListOrder::fromRequestValue(''));
    }

    public function testDescAndAscNameTheTwoOrders(): void
    {
        self::assertSame(ListOrder::NewestFirst, ListOrder::fromRequestValue('desc'));
        self::assertSame(ListOrder::OldestFirst, ListOrder::fromRequestValue('asc'));
    }

    public function testAnyOtherValueIsAValidationErrorOnTheOrderField(): void
    {
        try {
            ListOrder::fromRequestValue('ASC');
            self::fail('An unknown order must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame(['order' => ['Unknown order. Use one of: desc, asc.']], $exception->errors);
        }
    }

    public function testEachOrderNamesItsSqlDirectionAndComparisons(): void
    {
        $newest = ListOrder::NewestFirst;
        $oldest = ListOrder::OldestFirst;

        self::assertSame(
            ['DESC', '<', '>='],
            [$newest->sqlDirection(), $newest->strictlyAfter(), $newest->atOrBefore()],
        );
        self::assertSame(
            ['ASC', '>', '<='],
            [$oldest->sqlDirection(), $oldest->strictlyAfter(), $oldest->atOrBefore()],
        );
    }

    public function testArrangeKeepsANewestFirstListOrReversesItForOldestFirst(): void
    {
        self::assertSame([30, 20, 10], ListOrder::NewestFirst->arrange([30, 20, 10]));
        self::assertSame([10, 20, 30], ListOrder::OldestFirst->arrange([30, 20, 10]));
    }
}
