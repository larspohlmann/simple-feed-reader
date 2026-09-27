<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\RecommendationRunLog;
use App\Service\Recommendation\Run\CallSlot;
use PHPUnit\Framework\TestCase;

final class CallSlotTest extends TestCase
{
    public function testEachPhaseNamesItsRowAndOnlyABatchCarriesANumber(): void
    {
        self::assertSame(
            [
                [RecommendationRunLog::PHASE_DISTILL, null],
                [RecommendationRunLog::PHASE_BATCH, 3],
                [RecommendationRunLog::PHASE_CONSOLIDATE, null],
            ],
            array_map(
                static fn (CallSlot $slot): array => [$slot->phase, $slot->batchNumber],
                [CallSlot::distillation(), CallSlot::batch(3), CallSlot::consolidation()],
            ),
        );
    }
}
