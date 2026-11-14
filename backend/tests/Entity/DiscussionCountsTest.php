<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DiscussionCounts;
use PHPUnit\Framework\TestCase;

final class DiscussionCountsTest extends TestCase
{
    public function testUnknownCarriesNothing(): void
    {
        $counts = DiscussionCounts::unknown();

        self::assertTrue($counts->isUnknown());
        self::assertNull($counts->score);
        self::assertNull($counts->commentCount);
    }

    public function testACountsPairWithAnyNumberIsKnown(): void
    {
        self::assertFalse(DiscussionCounts::of(5, null)->isUnknown());
        self::assertFalse(DiscussionCounts::of(null, 0)->isUnknown());
        self::assertFalse(DiscussionCounts::of(0, 0)->isUnknown());
    }

    public function testACountsPairWithoutNumbersIsUnknown(): void
    {
        self::assertTrue(DiscussionCounts::of(null, null)->isUnknown());
    }
}
