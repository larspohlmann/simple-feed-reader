<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Run\Model;

use App\Enum\CallPhase;
use App\Service\Recommendation\Llm\Run\Model\CallSlotModel;
use PHPUnit\Framework\TestCase;

final class CallSlotModelTest extends TestCase
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
                static fn (CallSlotModel $slot): array => [$slot->phase, $slot->batchNumber],
                [CallSlotModel::distillation(), CallSlotModel::batch(3), CallSlotModel::consolidation()],
            ),
        );
    }
}
