<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Http\RecommendationDebugLogJson;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Recommendation\Feed\RecommendationDebugLog;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type DebugLogRow from RecommendationRunLogRepository
 */
final class RecommendationDebugLogJsonTest extends TestCase
{
    public function testAnAccountWithNoRetainedRunGetsAnEmptyPanel(): void
    {
        self::assertSame(
            ['entries' => [], 'run' => null, 'runs' => []],
            RecommendationDebugLogJson::list(RecommendationDebugLog::empty()),
        );
    }

    public function testEachRowCarriesItsStreamingTextOrNull(): void
    {
        $log = new RecommendationDebugLog(
            [self::row(7), self::row(8)],
            [8 => 'partial answer'],
            null,
            [],
        );

        $entries = RecommendationDebugLogJson::list($log)['entries'];

        self::assertNull($entries[0]['streamingText']);
        self::assertSame('partial answer', $entries[1]['streamingText']);
        self::assertSame(7, $entries[0]['id']);
    }

    public function testEachEntryCarriesItsTimesInAtomFormat(): void
    {
        $row = self::row(7);
        $row['finishedAt'] = new \DateTimeImmutable('2026-08-09T10:00:05Z');

        $entry = RecommendationDebugLogJson::list(new RecommendationDebugLog([$row], [], null, []))['entries'][0];

        self::assertSame('2026-08-09T10:00:00+00:00', $entry['createdAt']);
        self::assertSame('2026-08-09T10:00:05+00:00', $entry['finishedAt']);
    }

    public function testEachEntryCarriesItsPhaseAndVerdictAsWireStrings(): void
    {
        $row = self::row(7);
        $row['verdict'] = CallVerdict::TransportFailed;

        $entry = RecommendationDebugLogJson::list(new RecommendationDebugLog([$row], [], null, []))['entries'][0];

        self::assertSame('batch', $entry['phase']);
        self::assertSame('transport-failed', $entry['verdict']);
    }

    public function testDetailOfAStillStreamingCallHasNoVerdict(): void
    {
        $user = new User('detail-json@example.test', new \DateTimeImmutable());
        $run = new RecommendationRun($user, new \DateTimeImmutable());
        $log = new RecommendationRunLog($run, CallPhase::Batch, 1, 1, 'req', new \DateTimeImmutable());

        $detail = RecommendationDebugLogJson::detail($log);

        self::assertNull($detail['verdict']);
    }

    /** @return DebugLogRow */
    private static function row(int $id): array
    {
        return [
            'id' => $id,
            'runId' => 1,
            'phase' => CallPhase::Batch,
            'batchNumber' => 1,
            'attempt' => 1,
            'verdict' => null,
            'requestBytes' => 10,
            'responseBytes' => 20,
            'wireBytes' => 30,
            'createdAt' => new \DateTimeImmutable('2026-08-09T10:00:00Z'),
            'finishedAt' => null,
            'errorDetail' => null,
            'finishReason' => null,
        ];
    }
}
