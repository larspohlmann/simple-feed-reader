<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\Model;

use App\Service\Recommendation\Prompt\Model\PromptLineModel;
use App\Service\Recommendation\Run\Model\WaveBatchModel;
use PHPUnit\Framework\TestCase;

final class WaveBatchModelTest extends TestCase
{
    public function testItsLinesFollowTheSnapshotOrderAndSkipAPrunedEntry(): void
    {
        $three = new PromptLineModel(3, 'Three', 'F', 'D', null);
        $one = new PromptLineModel(1, 'One', 'F', 'D', null);
        $batch = new WaveBatchModel(0, [3, 2, 1], [1 => $one, 3 => $three]);

        self::assertSame([$three, $one], $batch->linesInSnapshotOrder());
    }
}
