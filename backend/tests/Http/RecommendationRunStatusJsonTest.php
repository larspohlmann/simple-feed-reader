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

    private function user(): User
    {
        return new User('eta@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    }

    private function emptySummary(): RecommendationForYouSummary
    {
        return new RecommendationForYouSummary(0, 0, null, null);
    }
}
