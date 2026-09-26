<?php

declare(strict_types=1);

namespace App\Tests\Service\Ordering;

use App\Service\Ordering\PositionReorderer;
use App\Tests\Support\RecordingPositioned;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PositionReordererTest extends TestCase
{
    public function testGivesEachItemItsIndexInTheRequestedOrderAndFlushesOnce(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $tenth = new RecordingPositioned();
        $twentieth = new RecordingPositioned();
        $thirtieth = new RecordingPositioned();

        (new PositionReorderer($entityManager))->reorder(
            [30, 10, 20],
            [10 => $tenth, 20 => $twentieth, 30 => $thirtieth],
        );

        self::assertSame([1, 2, 0], [$tenth->position, $twentieth->position, $thirtieth->position]);
    }
}
