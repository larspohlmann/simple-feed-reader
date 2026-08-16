<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\RecommendationRun;
use App\Http\RecommendationRunHistoryJson;
use App\Repository\RecommendationRunRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type HistoryRow from RecommendationRunRepository
 */
#[CoversClass(RecommendationRunHistoryJson::class)]
final class RecommendationRunHistoryJsonTest extends TestCase
{
    public function testRendersOneRowPerRunWithItsDuration(): void
    {
        $payload = RecommendationRunHistoryJson::payload([$this->completedRow()], 918_200_000);

        self::assertSame(918_200_000, $payload['totalCostNanoCredits']);
        self::assertCount(1, $payload['runs']);
        self::assertSame(42, $payload['runs'][0]['id']);
        self::assertSame('completed', $payload['runs'][0]['status']);
        self::assertSame('openrouter.ai', $payload['runs'][0]['providerHost']);
        self::assertSame('x-ai/grok-4-fast', $payload['runs'][0]['model']);
        self::assertSame(47, $payload['runs'][0]['durationSeconds']);
        self::assertSame('2026-08-16T09:12:00+00:00', $payload['runs'][0]['createdAt']);
        self::assertSame('2026-08-16T09:12:47+00:00', $payload['runs'][0]['completedAt']);
    }

    public function testAnUnfinishedRunHasNoDuration(): void
    {
        $row = $this->row(['status' => RecommendationRun::STATUS_RUNNING, 'completedAt' => null]);

        $payload = RecommendationRunHistoryJson::payload([$row], null);

        self::assertNull($payload['runs'][0]['durationSeconds']);
        self::assertNull($payload['runs'][0]['completedAt']);
        self::assertNull($payload['totalCostNanoCredits']);
    }

    /**
     * resume() puts a failed run back into RUNNING and deliberately leaves the
     * completedAt of the attempt that failed standing, so the row really does
     * arrive here carrying both. Reporting that time — and the duration of
     * that dead attempt — beside a RUNNING badge is the bug this guards.
     */
    public function testAResumedRunReportsNeitherACompletionTimeNorADuration(): void
    {
        $row = $this->row([
            'status' => RecommendationRun::STATUS_RUNNING,
            'completedAt' => new \DateTimeImmutable('2026-08-16 09:12:47'),
        ]);

        $payload = RecommendationRunHistoryJson::payload([$row], null);

        self::assertNull($payload['runs'][0]['completedAt']);
        self::assertNull($payload['runs'][0]['durationSeconds']);
    }

    public function testAPendingRunReportsNoCompletionEither(): void
    {
        $row = $this->row([
            'status' => RecommendationRun::STATUS_PENDING,
            'completedAt' => new \DateTimeImmutable('2026-08-16 09:12:47'),
        ]);

        $payload = RecommendationRunHistoryJson::payload([$row], null);

        self::assertNull($payload['runs'][0]['completedAt']);
        self::assertNull($payload['runs'][0]['durationSeconds']);
    }

    /**
     * A cancelled or failed run is over, so it reports when it ended just as a
     * completed one does — the gate is on the run being finished, not on it
     * having succeeded.
     */
    public function testEveryTerminalStatusReportsWhenItEnded(): void
    {
        foreach ([RecommendationRun::STATUS_FAILED, RecommendationRun::STATUS_CANCELLED] as $status) {
            $payload = RecommendationRunHistoryJson::payload([$this->row(['status' => $status])], null);

            self::assertSame('2026-08-16T09:12:47+00:00', $payload['runs'][0]['completedAt'], $status);
            self::assertSame(47, $payload['runs'][0]['durationSeconds'], $status);
        }
    }

    public function testCarriesEveryTokenCounter(): void
    {
        $payload = RecommendationRunHistoryJson::payload([$this->completedRow()], null);

        self::assertSame(118_432, $payload['runs'][0]['promptTokens']);
        self::assertSame(2_216, $payload['runs'][0]['completionTokens']);
        self::assertSame(880, $payload['runs'][0]['reasoningTokens']);
        self::assertSame(117_000, $payload['runs'][0]['cachedTokens']);
        self::assertSame(41_230_000, $payload['runs'][0]['costNanoCredits']);
    }

    /**
     * A scalar query may hand the BIGINT back as the driver's own string, and
     * the wire contract says the price is a number.
     */
    public function testACostHandedBackAsAStringGoesOutAsAnInteger(): void
    {
        $payload = RecommendationRunHistoryJson::payload([$this->row(['costNanoCredits' => '41230000'])], null);

        self::assertSame(41_230_000, $payload['runs'][0]['costNanoCredits']);
    }

    public function testAnUnpricedRunKeepsANullCostRatherThanAZeroOne(): void
    {
        $payload = RecommendationRunHistoryJson::payload([$this->row(['costNanoCredits' => null])], null);

        self::assertNull($payload['runs'][0]['costNanoCredits']);
    }

    /** @return HistoryRow */
    private function completedRow(): array
    {
        return $this->row([]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return HistoryRow
     */
    private function row(array $overrides): array
    {
        /** @var HistoryRow $row */
        $row = [
            'id' => 42,
            'status' => RecommendationRun::STATUS_COMPLETED,
            'providerHost' => 'openrouter.ai',
            'model' => 'x-ai/grok-4-fast',
            'createdAt' => new \DateTimeImmutable('2026-08-16 09:12:00'),
            'completedAt' => new \DateTimeImmutable('2026-08-16 09:12:47'),
            'promptTokens' => 118_432,
            'completionTokens' => 2_216,
            'reasoningTokens' => 880,
            'cachedTokens' => 117_000,
            'costNanoCredits' => 41_230_000,
            ...$overrides,
        ];

        return $row;
    }
}
