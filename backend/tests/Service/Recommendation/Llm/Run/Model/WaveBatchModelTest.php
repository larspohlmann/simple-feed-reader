<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Llm\Run\Model;

use App\Service\Recommendation\Llm\Run\Model\WaveBatchModel;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
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
}
