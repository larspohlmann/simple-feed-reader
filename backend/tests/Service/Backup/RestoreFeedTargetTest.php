<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\RestoreFeedTarget;
use PHPUnit\Framework\TestCase;

final class RestoreFeedTargetTest extends TestCase
{
    public function testKnowsTheRowsItWasBuiltWithAndTheOnesItLearns(): void
    {
        $target = new RestoreFeedTarget(7, true, ['old-hash' => 1]);
        self::assertTrue($target->knowsEntry('old-hash'));
        self::assertFalse($target->knowsEntry('new-hash'));
        self::assertNull($target->entryId('new-hash'));

        $target->learn(['new-hash' => 2]);

        self::assertTrue($target->knowsEntry('new-hash'));
        self::assertSame(2, $target->entryId('new-hash'));
        self::assertSame(1, $target->entryId('old-hash'));
    }

    public function testLearningKeepsWhatEarlierBatchesTaught(): void
    {
        $target = new RestoreFeedTarget(7, true, []);

        $target->learn(['first-batch' => 10]);
        $target->learn(['second-batch' => 11]);

        self::assertSame(10, $target->entryId('first-batch'));
        self::assertSame(11, $target->entryId('second-batch'));
    }
}
