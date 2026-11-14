<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Support;

use App\Service\Fetch\Support\CapacityGuard;
use PHPUnit\Framework\TestCase;

final class CapacityGuardTest extends TestCase
{
    public function testACapacityOfOneIsAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        CapacityGuard::atLeastOne(1, 'Slot capacity');
    }

    public function testACapacityBelowOneIsRejectedUnderItsName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot capacity must be at least 1, got 0.');

        CapacityGuard::atLeastOne(0, 'Slot capacity');
    }
}
