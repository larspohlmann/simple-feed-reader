<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Http\RecommendationRunStatusJson;
use App\Service\Recommendation\RecommendationForYouSummary;
use App\Service\Recommendation\RecommendationRunReport;
use App\Service\Recommendation\RecommendationRunStatus;
use PHPUnit\Framework\TestCase;

final class RecommendationRunStatusJsonTest extends TestCase
{
    public function testElapsedSecondsIsWholeSecondsSinceStartedAt(): void
    {
        $startedAt = new \DateTimeImmutable('2026-08-09T10:00:00');
        $report = RecommendationRunReport::fromRun(new RecommendationRun($this->user(), $startedAt));

        $json = RecommendationRunStatusJson::report(
            new RecommendationRunStatus($report, $this->emptySummary(), $startedAt->modify('+90 seconds'), null),
        );

        self::assertSame(90, $json['elapsedSeconds']);
    }

    public function testEtaSecondsEchoesTheEstimatePassedIn(): void
    {
        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::none(),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            42,
        ));

        self::assertSame(42, $json['etaSeconds']);
    }

    public function testReportsWhetherTheFirstBatchHasStarted(): void
    {
        $run = new RecommendationRun($this->user(), new \DateTimeImmutable('2026-08-09T10:00:00'));
        $run->snapshot([[1]]);
        $run->markFirstBatchStarted();

        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::fromRun($run),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            42,
        ));

        self::assertTrue($json['firstBatchStarted']);
    }

    public function testTreatsCompletedBatchesAsAStartedFirstBatchForExistingRuns(): void
    {
        $run = new RecommendationRun($this->user(), new \DateTimeImmutable('2026-08-09T10:00:00'));
        $run->snapshot([[1]]);
        $run->recordBatchWinners([]);

        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::fromRun($run),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            42,
        ));

        self::assertTrue($json['firstBatchStarted']);
    }

    public function testElapsedSecondsClampsToZeroWhenTheClockIsBehindStartedAt(): void
    {
        $startedAt = new \DateTimeImmutable('2026-08-09T10:00:00');
        $report = RecommendationRunReport::fromRun(new RecommendationRun($this->user(), $startedAt));

        $json = RecommendationRunStatusJson::report(
            new RecommendationRunStatus($report, $this->emptySummary(), $startedAt->modify('-5 seconds'), null),
        );

        self::assertSame(0, $json['elapsedSeconds']);
    }

    public function testElapsedSecondsIsNullWhenThereIsNoRun(): void
    {
        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::none(),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            null,
        ));

        self::assertNull($json['elapsedSeconds']);
    }

    public function testForYouCarriesTheNewestCompletedRunId(): void
    {
        $summary = new RecommendationForYouSummary(4, 9, new \DateTimeImmutable('2026-08-09T10:00:00Z'), 42);

        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::none(),
            $summary,
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            null,
        ));

        $forYou = $json['forYou'];
        self::assertIsArray($forYou);
        self::assertSame(42, $forYou['newestRunId']);
    }

    public function testItSendsTheRunReportFieldsInTheirWireOrder(): void
    {
        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::busy()->waitingForLock(),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            null,
        ));

        self::assertSame(
            [
                'status' => 'busy',
                'batchesTotal' => null,
                'batchesDone' => 0,
                'error' => null,
                'background' => false,
                'waitingForLock' => true,
                'streamedChars' => 0,
                'firstBatchStarted' => false,
                'elapsedSeconds' => null,
                'etaSeconds' => null,
                'forYou' => ['itemCount' => 0, 'totalCount' => 0, 'generatedAt' => null, 'newestRunId' => null],
            ],
            $json,
        );
    }

    /**
     * M6: distinct values on every same-typed neighbour pair the report above
     * leaves at null/0/false, so a field swap in the mapper fails here.
     */
    public function testItSendsADistinctRunReportInTheirWireOrder(): void
    {
        $startedAt = new \DateTimeImmutable('2026-08-09T10:00:00');
        $run = new RecommendationRun($this->user(), $startedAt);
        $run->snapshot([[1], [2], [3]]);
        $run->recordBatchWinners([]);
        $run->fail('boom', $startedAt);

        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::fromRun($run),
            $this->emptySummary(),
            $startedAt->modify('+90 seconds'),
            42,
        ));

        self::assertSame(
            [
                'status' => 'failed',
                'batchesTotal' => 5,
                'batchesDone' => 1,
                'error' => 'boom',
                'background' => false,
                'waitingForLock' => false,
                'streamedChars' => 0,
                'firstBatchStarted' => true,
                'elapsedSeconds' => 90,
                'etaSeconds' => 42,
                'forYou' => ['itemCount' => 0, 'totalCount' => 0, 'generatedAt' => null, 'newestRunId' => null],
            ],
            $json,
        );
    }

    private function user(): User
    {
        return new User('eta@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    }

    private function emptySummary(): RecommendationForYouSummary
    {
        return new RecommendationForYouSummary(0, 0, null, null);
    }
}
