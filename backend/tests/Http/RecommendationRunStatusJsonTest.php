<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Http\RecommendationRunStatusJson;
use App\Service\Recommendation\RecommendationForYouSummary;
use App\Service\Recommendation\RecommendationRunReport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RecommendationRunStatusJsonTest extends TestCase
{
    public function testElapsedSecondsIsWholeSecondsSinceStartedAt(): void
    {
        $startedAt = new \DateTimeImmutable('2026-08-09T10:00:00');
        $report = RecommendationRunReport::fromRun(new RecommendationRun($this->user(), $startedAt));
        $clock = new MockClock($startedAt->modify('+90 seconds'));

        $json = RecommendationRunStatusJson::report($report, $this->emptySummary(), $clock);

        self::assertSame(90, $json['elapsedSeconds']);
    }

    public function testElapsedSecondsClampsToZeroWhenTheClockIsBehindStartedAt(): void
    {
        $startedAt = new \DateTimeImmutable('2026-08-09T10:00:00');
        $report = RecommendationRunReport::fromRun(new RecommendationRun($this->user(), $startedAt));
        $clock = new MockClock($startedAt->modify('-5 seconds'));

        $json = RecommendationRunStatusJson::report($report, $this->emptySummary(), $clock);

        self::assertSame(0, $json['elapsedSeconds']);
    }

    public function testElapsedSecondsIsNullWhenThereIsNoRun(): void
    {
        $json = RecommendationRunStatusJson::report(
            RecommendationRunReport::none(),
            $this->emptySummary(),
            new MockClock('2026-08-09T10:00:00'),
        );

        self::assertNull($json['elapsedSeconds']);
    }

    public function testForYouCarriesTheNewestCompletedRunId(): void
    {
        $summary = new RecommendationForYouSummary(4, new \DateTimeImmutable('2026-08-09T10:00:00Z'), 42);

        $json = RecommendationRunStatusJson::report(
            RecommendationRunReport::none(),
            $summary,
            new MockClock('2026-08-09T10:00:00'),
        );

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
        return new RecommendationForYouSummary(0, null, null);
    }
}
