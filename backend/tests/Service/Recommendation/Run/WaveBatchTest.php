<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Service\Recommendation\Prompt\PromptLine;
use App\Service\Recommendation\Run\WaveBatch;
use PHPUnit\Framework\TestCase;

final class WaveBatchTest extends TestCase
{
    public function testItsLinesFollowTheSnapshotOrderAndSkipAPrunedEntry(): void
    {
        $three = new PromptLine(3, 'Three', 'F', 'D', null);
        $one = new PromptLine(1, 'One', 'F', 'D', null);
        $batch = new WaveBatch(0, [3, 2, 1], [1 => $one, 3 => $three]);

        self::assertSame([$three, $one], $batch->linesInSnapshotOrder());
    }
}
