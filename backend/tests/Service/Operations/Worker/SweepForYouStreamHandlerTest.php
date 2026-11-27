<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Worker;

use App\Service\Curation\Recommendation\Stream\ForYouStreamSweep;
use App\Service\Operations\Worker\Handler\SweepForYouStreamHandler;
use App\Service\Operations\Worker\Message\SweepForYouStream;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SeedsForYouStreamBacklog;
use App\Tests\Support\TickingClock;

final class SweepForYouStreamHandlerTest extends DbTestCase
{
    use SeedsForYouStreamBacklog;

    public function testTheHandlerSweepsAndLogsTheReport(): void
    {
        $logger = new RecordingLogger();
        $sweep = self::getContainer()->get(ForYouStreamSweep::class);
        self::assertInstanceOf(ForYouStreamSweep::class, $sweep);

        (new SweepForYouStreamHandler($sweep, $logger))(new SweepForYouStream());

        self::assertCount(1, $logger->records);
        self::assertSame('Worker For you stream sweep finished.', $logger->records[0]['message']);
        self::assertSame(
            ['usersSwept' => 0, 'entriesScanned' => 0, 'matchesInserted' => 0, 'caughtUp' => true],
            $logger->records[0]['context']['report'],
        );
    }

    public function testTheBudgetIsSixtySeconds(): void
    {
        $this->seedOneEntryBacklog('handler-budget-a@example.test');
        $withinBudget = $this->scannedAfterStepSeconds(59);
        $this->seedOneEntryBacklog('handler-budget-b@example.test');
        $atBudget = $this->scannedAfterStepSeconds(60);

        self::assertSame(1, $withinBudget);
        self::assertSame(0, $atBudget);
    }

    private function scannedAfterStepSeconds(int $step): int
    {
        $logger = new RecordingLogger();
        $sweep = $this->sweepOnClock(new TickingClock(new \DateTimeImmutable('2026-11-27 10:00:00'), $step));

        (new SweepForYouStreamHandler($sweep, $logger))(new SweepForYouStream());

        $report = $logger->records[0]['context']['report'];
        self::assertIsArray($report);
        self::assertIsInt($report['entriesScanned']);

        return $report['entriesScanned'];
    }
}
