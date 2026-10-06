<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\Model;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use PHPUnit\Framework\TestCase;

final class WaveBatchModelTest extends TestCase
{
    public function testItsLinesFollowTheSnapshotOrderAndSkipAPrunedEntry(): void
    {
        $three = new ArticleLineModel(3, 'Three', 'F', 'D', null);
        $one = new ArticleLineModel(1, 'One', 'F', 'D', null);
        $batch = new WaveBatchModel(0, [3, 2, 1], [1 => $one, 3 => $three]);

        self::assertSame([$three, $one], $batch->linesInSnapshotOrder());
    }

    public function testAnUnprunedBatchRequiresItsLines(): void
    {
        $one = new ArticleLineModel(1, 'One', 'F', 'D', null);

        self::assertSame([$one], (new WaveBatchModel(0, [2, 1], [1 => $one]))->requireLinesInSnapshotOrder());
    }

    public function testAFullyPrunedBatchHasNoLinesToRequire(): void
    {
        $this->expectExceptionObject(new \LogicException('Batch 4 was pruned to nothing and makes no call.'));

        (new WaveBatchModel(4, [2, 1], []))->requireLinesInSnapshotOrder();
    }
}
