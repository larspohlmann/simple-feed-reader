<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\Worker;

use App\Service\Curation\YourStream\YourStreamSweep;
use App\Service\Operations\Worker\Handler\SweepYourStreamHandler;
use App\Service\Operations\Worker\Message\SweepYourStream;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SeedsYourStreamBacklog;
use App\Tests\Support\TickingClock;

final class SweepYourStreamHandlerTest extends DbTestCase
{
    use SeedsYourStreamBacklog;

    public function testTheHandlerSweepsAndLogsTheReport(): void
    {
        $logger = new RecordingLogger();
        $sweep = self::getContainer()->get(YourStreamSweep::class);
        self::assertInstanceOf(YourStreamSweep::class, $sweep);

        (new SweepYourStreamHandler($sweep, $logger))(new SweepYourStream());

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

        (new SweepYourStreamHandler($sweep, $logger))(new SweepYourStream());

        $report = $logger->records[0]['context']['report'];
        self::assertIsArray($report);
        self::assertIsInt($report['entriesScanned']);

        return $report['entriesScanned'];
    }
}
