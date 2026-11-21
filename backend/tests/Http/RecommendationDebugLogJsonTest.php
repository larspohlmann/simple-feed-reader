<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\AiRunKind;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Enum\RequestAdjustment;
use App\Enum\RunStatus;
use App\Http\RecommendationDebugLogJson;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Curation\Recommendation\Feed\Model\DebugLogRunModel;
use App\Service\Curation\Recommendation\Feed\Model\RecommendationDebugLogModel;
use App\Service\Curation\Recommendation\Feed\Model\RunCallCountersModel;
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
        $log = RecommendationRunLog::forRun($run, CallPhase::Batch, 1, 1, 'req', new \DateTimeImmutable());

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

    public function testAnEntryWithoutAnAdjustmentCarriesNull(): void
    {
        $log = new RecommendationDebugLogModel([self::row(7)], [], null, []);
        $entry = RecommendationDebugLogJson::list($log)['entries'][0];

        self::assertNull($entry['adjustment']);
        self::assertArrayNotHasKey('adjustedSetting', $entry);
        self::assertArrayNotHasKey('adjustedValue', $entry);
    }

    public function testAnAdjustedEntryCarriesTheSettingAsAWireStringAndItsValue(): void
    {
        $row = self::row(7);
        $row['adjustedSetting'] = RequestAdjustment::OutputCap;
        $row['adjustedValue'] = '32768';

        $entry = RecommendationDebugLogJson::list(new RecommendationDebugLogModel([$row], [], null, []))['entries'][0];

        self::assertSame(['setting' => 'output-cap', 'value' => '32768'], $entry['adjustment']);
    }

    public function testAStoryRunCarriesNullWhereItRecordsNothing(): void
    {
        $storyRun = new DebugLogRunModel(
            AiRunKind::Story,
            42,
            null,
            null,
            null,
            new \DateTimeImmutable('2026-08-09T10:00:00Z'),
            null,
        );

        $payload = RecommendationDebugLogJson::list(new RecommendationDebugLogModel([], [], $storyRun, [$storyRun]));

        self::assertSame(
            [
                'kind' => 'story',
                'id' => 42,
                'status' => null,
                'error' => null,
                'attempts' => null,
                'maxAttempts' => null,
                'transportFailures' => null,
                'maxTransportFailures' => null,
                'createdAt' => '2026-08-09T10:00:00+00:00',
                'completedAt' => null,
            ],
            $payload['run'],
        );
        self::assertSame(
            [['kind' => 'story', 'id' => 42, 'status' => null, 'createdAt' => '2026-08-09T10:00:00+00:00']],
            $payload['runs'],
        );
    }

    public function testACountedRunCarriesItsCountersAndTimes(): void
    {
        $run = new DebugLogRunModel(
            AiRunKind::Profile,
            7,
            RunStatus::Failed,
            'No usable reply.',
            new RunCallCountersModel(2, 3, 1, 4),
            new \DateTimeImmutable('2026-08-09T10:00:00Z'),
            new \DateTimeImmutable('2026-08-09T10:01:00Z'),
        );

        self::assertSame(
            [
                'kind' => 'profile',
                'id' => 7,
                'status' => 'failed',
                'error' => 'No usable reply.',
                'attempts' => 2,
                'maxAttempts' => 3,
                'transportFailures' => 1,
                'maxTransportFailures' => 4,
                'createdAt' => '2026-08-09T10:00:00+00:00',
                'completedAt' => '2026-08-09T10:01:00+00:00',
            ],
            RecommendationDebugLogJson::list(new RecommendationDebugLogModel([], [], $run, []))['run'],
        );
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
            'adjustedSetting' => null,
            'adjustedValue' => null,
        ];
    }
}
