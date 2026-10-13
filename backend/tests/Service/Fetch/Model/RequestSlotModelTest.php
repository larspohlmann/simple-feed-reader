<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\RequestSlotModel;
use PHPUnit\Framework\TestCase;

final class RequestSlotModelTest extends TestCase
{
    public function testANamedSlotsKeyIsItsNameWhateverTheUrl(): void
    {
        $slot = RequestSlotModel::named('social-account:7', 3);

        self::assertSame('slot:social-account:7', $slot->keyFor('https://one.example.com/a'));
        self::assertSame('slot:social-account:7', $slot->keyFor('https://two.example.com/b'));
    }

    public function testAPerHostSlotsKeyIsTheHostTheUrlHasReached(): void
    {
        $slot = RequestSlotModel::perHost(2);

        self::assertSame('host:example.com', $slot->keyFor('https://www.example.com:443/feed'));
        self::assertSame('host:other.example.com', $slot->keyFor('https://other.example.com/feed'));
    }

    public function testCarriesItsCapacity(): void
    {
        self::assertSame(4, RequestSlotModel::named('social-host:example.com', 4)->capacity);
        self::assertSame(2, RequestSlotModel::perHost(2)->capacity);
    }

    public function testRejectsACapacityBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot capacity must be at least 1, got 0.');

        RequestSlotModel::perHost(0);
    }
}
