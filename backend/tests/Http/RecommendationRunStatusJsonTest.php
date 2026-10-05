<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Http\RecommendationRunStatusJson;
use App\Service\Recommendation\Feed\Model\RecommendationForYouSummaryModel;
use App\Service\Recommendation\Feed\Model\RecommendationRunStatusModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Model\RunForecastModel;
use PHPUnit\Framework\TestCase;

final class RecommendationRunStatusJsonTest extends TestCase
{
    public function testElapsedSecondsIsWholeSecondsSinceStartedAt(): void
    {
        $startedAt = new \DateTimeImmutable('2026-08-09T10:00:00');
        $report = RecommendationRunReportModel::fromRun(new RecommendationRun($this->user(), $startedAt));

        $json = RecommendationRunStatusJson::report(
            new RecommendationRunStatusModel($report, $this->emptySummary(), $startedAt->modify('+90 seconds'), null),
        );

        self::assertSame(90, $json['elapsedSeconds']);
    }

    public function testEchoesTheForecastPassedIn(): void
    {
        $json = RecommendationRunStatusJson::report(new RecommendationRunStatusModel(
            RecommendationRunReportModel::none(),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            new RunForecastModel(42, 0.25),
        ));

        self::assertSame(42, $json['etaSeconds']);
        self::assertSame(0.25, $json['finishedShare']);
    }

    public function testWithoutAForecastBothEstimatesAreNull(): void
    {
        $json = RecommendationRunStatusJson::report(new RecommendationRunStatusModel(
            RecommendationRunReportModel::none(),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            null,
        ));

        self::assertNull($json['etaSeconds']);
        self::assertNull($json['finishedShare']);
    }

    public function testReportsWhetherTheFirstBatchHasStarted(): void
    {
        $run = new RecommendationRun($this->user(), new \DateTimeImmutable('2026-08-09T10:00:00'));
        $run->snapshot(RecommendationEngineKind::Llm, null, [[1]]);
        $run->markFirstBatchStarted();

        $json = RecommendationRunStatusJson::report(new RecommendationRunStatusModel(
            RecommendationRunReportModel::fromRun($run),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            null,
        ));

        self::assertTrue($json['firstBatchStarted']);
    }

    public function testTreatsCompletedBatchesAsAStartedFirstBatchForExistingRuns(): void
    {
        $run = new RecommendationRun($this->user(), new \DateTimeImmutable('2026-08-09T10:00:00'));
        $run->snapshot(RecommendationEngineKind::Llm, null, [[1]]);
        $run->recordBatchWinners([]);

        $json = RecommendationRunStatusJson::report(new RecommendationRunStatusModel(
            RecommendationRunReportModel::fromRun($run),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            null,
        ));

        self::assertTrue($json['firstBatchStarted']);
    }

    public function testElapsedSecondsClampsToZeroWhenTheClockIsBehindStartedAt(): void
    {
        $startedAt = new \DateTimeImmutable('2026-08-09T10:00:00');
        $report = RecommendationRunReportModel::fromRun(new RecommendationRun($this->user(), $startedAt));

        $json = RecommendationRunStatusJson::report(
            new RecommendationRunStatusModel($report, $this->emptySummary(), $startedAt->modify('-5 seconds'), null),
        );

        self::assertSame(0, $json['elapsedSeconds']);
    }

    public function testElapsedSecondsIsNullWhenThereIsNoRun(): void
    {
        $json = RecommendationRunStatusJson::report(new RecommendationRunStatusModel(
            RecommendationRunReportModel::none(),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            null,
        ));

        self::assertNull($json['elapsedSeconds']);
    }

    public function testForYouCarriesTheNewestCompletedRunId(): void
    {
        $summary = new RecommendationForYouSummaryModel(4, 9, new \DateTimeImmutable('2026-08-09T10:00:00Z'), 42);

        $json = RecommendationRunStatusJson::report(new RecommendationRunStatusModel(
            RecommendationRunReportModel::none(),
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
        $json = RecommendationRunStatusJson::report(new RecommendationRunStatusModel(
            RecommendationRunReportModel::busy()->waitingForLock(),
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
                'waitingForProfile' => false,
                'resumable' => false,
                'streamedChars' => 0,
                'firstBatchStarted' => false,
                'elapsedSeconds' => null,
                'etaSeconds' => null,
                'finishedShare' => null,
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
        $run->snapshot(RecommendationEngineKind::Llm, null, [[1], [2], [3]]);
        $run->recordBatchWinners([]);
        $run->fail('boom', $startedAt);

        $json = RecommendationRunStatusJson::report(new RecommendationRunStatusModel(
            RecommendationRunReportModel::fromRun($run),
            $this->emptySummary(),
            $startedAt->modify('+90 seconds'),
            new RunForecastModel(42, 0.25),
            resumable: true,
        ));

        self::assertSame(
            [
                'status' => 'failed',
                'batchesTotal' => 4,
                'batchesDone' => 1,
                'error' => 'boom',
                'background' => false,
                'waitingForLock' => false,
                'waitingForProfile' => false,
                'resumable' => true,
                'streamedChars' => 0,
                'firstBatchStarted' => true,
                'elapsedSeconds' => 90,
                'etaSeconds' => 42,
                'finishedShare' => 0.25,
                'forYou' => ['itemCount' => 0, 'totalCount' => 0, 'generatedAt' => null, 'newestRunId' => null],
            ],
            $json,
        );
    }

    public function testAWaitingReportSaysItWaitsForItsProfile(): void
    {
        $json = RecommendationRunStatusJson::report(new RecommendationRunStatusModel(
            RecommendationRunReportModel::none(),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            null,
            waitingForProfile: true,
        ));

        self::assertTrue($json['waitingForProfile']);
    }

    private function user(): User
    {
        return new User('eta@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    }

    private function emptySummary(): RecommendationForYouSummaryModel
    {
        return new RecommendationForYouSummaryModel(0, 0, null, null);
    }
}
