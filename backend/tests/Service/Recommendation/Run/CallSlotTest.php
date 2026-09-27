<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Enum\CallPhase;
use App\Service\Recommendation\Run\CallSlot;
use PHPUnit\Framework\TestCase;

final class CallSlotTest extends TestCase
{
    public function testEachPhaseNamesItsRowAndOnlyABatchCarriesANumber(): void
    {
        self::assertSame(
            [
                [CallPhase::Distill, null],
                [CallPhase::Batch, 3],
                [CallPhase::Consolidate, null],
            ],
            array_map(
                static fn (CallSlot $slot): array => [$slot->phase, $slot->batchNumber],
                [CallSlot::distillation(), CallSlot::batch(3), CallSlot::consolidation()],
            ),
        );
    }
}
