<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Http\RecommendationRunHistoryJson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecommendationRunHistoryJson::class)]
final class RecommendationRunHistoryJsonTest extends TestCase
{
    public function testRendersOneRowPerRunWithItsDuration(): void
    {
        $run = $this->completedRun();

        $payload = RecommendationRunHistoryJson::payload([$run], 918_200_000);

        self::assertSame(918_200_000, $payload['totalCostNanoCredits']);
        self::assertCount(1, $payload['runs']);
        self::assertSame('completed', $payload['runs'][0]['status']);
        self::assertSame('openrouter.ai', $payload['runs'][0]['providerHost']);
        self::assertSame('x-ai/grok-4-fast', $payload['runs'][0]['model']);
        self::assertSame(47, $payload['runs'][0]['durationSeconds']);
        self::assertSame('2026-08-16T09:12:00+00:00', $payload['runs'][0]['createdAt']);
        self::assertSame('2026-08-16T09:12:47+00:00', $payload['runs'][0]['completedAt']);
    }

    public function testAnUnfinishedRunHasNoDuration(): void
    {
        $run = new RecommendationRun($this->user(), new \DateTimeImmutable('2026-08-16 09:12:00'));

        $payload = RecommendationRunHistoryJson::payload([$run], null);

        self::assertNull($payload['runs'][0]['durationSeconds']);
        self::assertNull($payload['runs'][0]['completedAt']);
        self::assertNull($payload['totalCostNanoCredits']);
    }

    public function testCarriesEveryTokenCounter(): void
    {
        $payload = RecommendationRunHistoryJson::payload([$this->completedRun()], null);

        self::assertSame(0, $payload['runs'][0]['promptTokens']);
        self::assertSame(0, $payload['runs'][0]['completionTokens']);
        self::assertSame(0, $payload['runs'][0]['reasoningTokens']);
        self::assertSame(0, $payload['runs'][0]['cachedTokens']);
        self::assertNull($payload['runs'][0]['costNanoCredits']);
    }

    private function completedRun(): RecommendationRun
    {
        $run = new RecommendationRun($this->user(), new \DateTimeImmutable('2026-08-16 09:12:00'));
        $run->stampProvider('openrouter.ai', 'x-ai/grok-4-fast');
        $run->snapshot([[1]]);
        $run->recordBatchWinners([]);
        $run->complete(new \DateTimeImmutable('2026-08-16 09:12:47'));

        return $run;
    }

    // The mapper never reads anything off User beyond RecommendationRun's own
    // constructor requiring one; this codebase's User has no no-args
    // constructor, unlike the brief's sketch.
    private function user(): User
    {
        return new User('history-mapper@example.test', new \DateTimeImmutable('2026-08-16 09:00:00'));
    }
}
