<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model\RequestSlot;

use App\Service\Fetch\Model\RequestSlot\HostSlotModel;
use PHPUnit\Framework\TestCase;

final class HostSlotModelTest extends TestCase
{
    public function testItsKeyIsTheHostTheAttemptHasReached(): void
    {
        $slot = new HostSlotModel(2);

        self::assertSame('example.com', $slot->keyFor('https://www.example.com:443/feed'));
        self::assertSame('other.example.com', $slot->keyFor('https://other.example.com/feed'));
    }

    public function testCarriesItsCapacity(): void
    {
        $slot = new HostSlotModel(2);

        self::assertSame(2, $slot->capacity());
    }

    public function testRejectsACapacityBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot capacity must be at least 1, got 0.');

        new HostSlotModel(0);
    }
}
