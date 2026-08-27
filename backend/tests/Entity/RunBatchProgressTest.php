<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RunBatchProgress;
use PHPUnit\Framework\TestCase;

final class RunBatchProgressTest extends TestCase
{
    public function testTracksTheFirstBatchStartAndCompletedBatchesSeparately(): void
    {
        $progress = new RunBatchProgress();

        self::assertFalse($progress->hasFirstBatchStarted());
        self::assertSame(0, $progress->batchesDone());

        $progress->markFirstBatchStarted();
        $progress->recordCompletedBatch();

        self::assertTrue($progress->hasFirstBatchStarted());
        self::assertSame(1, $progress->batchesDone());
    }
}
