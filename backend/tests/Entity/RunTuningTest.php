<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RunTuning;
use PHPUnit\Framework\TestCase;

final class RunTuningTest extends TestCase
{
    public function testMaxBatchConcurrencyIsEight(): void
    {
        self::assertSame(8, RunTuning::MAX_BATCH_CONCURRENCY);
    }

    public function testAConcurrencyWithinTheCeilingIsKept(): void
    {
        self::assertSame(3, $this->tuningAt(3)->cappedBatchConcurrency());
    }

    public function testAConcurrencyAboveTheCeilingIsClampedToIt(): void
    {
        self::assertSame(
            RunTuning::MAX_BATCH_CONCURRENCY,
            $this->tuningAt(RunTuning::MAX_BATCH_CONCURRENCY + 1)->cappedBatchConcurrency(),
        );
    }

    private function tuningAt(int $batchConcurrency): RunTuning
    {
        $tuning = new RunTuning();
        $tuning->setBatchConcurrency($batchConcurrency);

        return $tuning;
    }
}
