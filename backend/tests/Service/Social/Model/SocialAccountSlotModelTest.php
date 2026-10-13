<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Social\Model\SocialAccountSlotModel;
use PHPUnit\Framework\TestCase;

final class SocialAccountSlotModelTest extends TestCase
{
    public function testItsKeyIsTheAccountWhateverTheUrl(): void
    {
        $slot = new SocialAccountSlotModel(7, 3);

        self::assertSame('7', $slot->keyFor('https://one.example.com/a'));
        self::assertSame('7', $slot->keyFor('https://two.example.com/b'));
    }

    public function testCarriesItsCapacity(): void
    {
        $slot = new SocialAccountSlotModel(7, 3);

        self::assertSame(3, $slot->capacity());
    }

    public function testRejectsACapacityBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot capacity must be at least 1, got 0.');

        new SocialAccountSlotModel(7, 0);
    }
}
