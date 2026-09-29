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
use App\Service\Recommendation\Feed\Model\RecommendationDebugLogModel;
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
            RecommendationDebugLogJson::list(RecommendationDebugLogModel::empty()),
        );
    }

    public function testEachRowCarriesItsStreamingTextOrNull(): void
    {
        $log = new RecommendationDebugLogModel(
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

        $entry = RecommendationDebugLogJson::list(new RecommendationDebugLogModel([$row], [], null, []))['entries'][0];

        self::assertSame('2026-08-09T10:00:00+00:00', $entry['createdAt']);
        self::assertSame('2026-08-09T10:00:05+00:00', $entry['finishedAt']);
    }

    public function testEachEntryCarriesItsPhaseAndVerdictAsWireStrings(): void
    {
        $row = self::row(7);
        $row['verdict'] = CallVerdict::TransportFailed;

        $entry = RecommendationDebugLogJson::list(new RecommendationDebugLogModel([$row], [], null, []))['entries'][0];

        self::assertSame('batch', $entry['phase']);
        self::assertSame('transport-failed', $entry['verdict']);
    }

    /**
     * assertNull() alone cannot tell a nullsafe read apart from a plain one:
     * reading ->value straight off null still yields null, only with a
     * warning, so the mutant has to be caught by the warning itself.
     */
    public function testDetailOfAStillStreamingCallHasNoVerdict(): void
    {
        $user = new User('detail-json@example.test', new \DateTimeImmutable());
        $run = new RecommendationRun($user, new \DateTimeImmutable());
        $log = new RecommendationRunLog($run, CallPhase::Batch, 1, 1, 'req', new \DateTimeImmutable());

        $warningRaised = false;
        set_error_handler(static function () use (&$warningRaised): bool {
            $warningRaised = true;

            return true;
        }, E_WARNING);

        try {
            $detail = RecommendationDebugLogJson::detail($log);
        } finally {
            restore_error_handler();
        }

        self::assertFalse($warningRaised, 'Reading a null verdict must raise no warning.');
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
