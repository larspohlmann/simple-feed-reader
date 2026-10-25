<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory\Model;

use App\Service\SubscriptionHistory\Model\DayCountModel;
use PHPUnit\Framework\TestCase;

final class DayCountModelTest extends TestCase
{
    public function testNoneIsZeroOnBothCounts(): void
    {
        $none = DayCountModel::none();

        self::assertSame(0, $none->arrived);
        self::assertSame(0, $none->read);
    }

    public function testPlusAddsEachCountSeparately(): void
    {
        $sum = (new DayCountModel(3, 1))->plus(new DayCountModel(4, 2));

        self::assertSame(7, $sum->arrived);
        self::assertSame(3, $sum->read);
    }
}
