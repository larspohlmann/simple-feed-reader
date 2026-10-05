<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\CallOutcome;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Enum\RecommendationEngineKind;
use App\Enum\ScoringProtocol;
use App\Repository\RecommendationRunTimingRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\RecommendationRunForecaster;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationRunForecasterTest extends DbTestCase
{
    private const string RUN_START = '2026-08-08T12:00:00Z';
    private const string HISTORY_START = '2026-08-07T09:00:00Z';

    private User $user;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('eta-owner@example.test');

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    public function testWeightsTheTailPhasesFromHistoryAndSubtractsElapsed(): void
    {
        // History: pickup 10s, batch phase 40s over 4 batches (10s/batch),
        // consolidate 30s. A live run with 3 batches is predicted to take
        // 10 + 3×10 + 30 = 70s; 20s in, 50s remain.
        $this->seedHistoricalRun(pickup: 10, batchWall: 40, batches: 4, consolidate: 30);
        $report = $this->liveReportWithBatches(3);

        $eta = $this->forecasterAt('+20 seconds')->forecast($report, $this->user)?->etaSeconds;

        self::assertSame(50, $eta);
    }

    public function testPinsAtZeroOnceElapsedPassesThePrediction(): void
    {
        $this->seedHistoricalRun(pickup: 10, batchWall: 40, batches: 4, consolidate: 30);
        $report = $this->liveReportWithBatches(3); // predicted 70s

        $eta = $this->forecasterAt('+200 seconds')->forecast($report, $this->user)?->etaSeconds;

        self::assertSame(0, $eta);
    }

    /** Of a 70 s three-batch run (10 s pickup, 10 s a batch, 30 s consolidate), two finished batches are 20 s. */
    public function testWeighsTheFinishedBatchesAgainstThePredictedRun(): void
    {
        $this->seedHistoricalRun(pickup: 10, batchWall: 40, batches: 4, consolidate: 30);

        $forecast = $this->forecasterAt('+20 seconds')->forecast($this->liveReportWithBatches(3, 2), $this->user);

        self::assertNotNull($forecast);
        self::assertEqualsWithDelta(20 / 70, $forecast->finishedShare, 1e-9);
    }

    /** 40 s over 3 batches is 13.3 s a batch: one batch leaves 33.3 s, two leave 46.7 s, 20 s in. */
    public function testRoundsTheRemainingSecondsToTheNearest(): void
    {
        $this->seedHistoricalRun(pickup: 10, batchWall: 40, batches: 3, consolidate: 30);
        $forecaster = $this->forecasterAt('+20 seconds');

        self::assertSame(33, $forecaster->forecast($this->liveReportWithBatches(1), $this->user)?->etaSeconds);
        self::assertSame(47, $forecaster->forecast($this->liveReportWithBatches(2), $this->user)?->etaSeconds);
    }

    public function testReturnsNullWithoutAnyCompletedHistory(): void
    {
        $report = $this->liveReportWithBatches(3);

        self::assertNull($this->forecasterAt('+20 seconds')->forecast($report, $this->user));
    }

    public function testReturnsNullBeforeTheFirstBatchStarts(): void
    {
        $this->seedHistoricalRun(pickup: 10, batchWall: 40, batches: 4, consolidate: 30);
        $run = new RecommendationRun($this->user, new \DateTimeImmutable(self::RUN_START));
        $run->snapshot(RecommendationEngineKind::Llm, null, [[1], [2], [3]]);

        $forecast = $this->forecasterAt('+20 seconds')->forecast(
            RecommendationRunReportModel::fromRun($run),
            $this->user,
        );

        self::assertNull($forecast);
    }

    public function testReturnsNullWhenNoRunIsInFlight(): void
    {
        $this->seedHistoricalRun(pickup: 10, batchWall: 40, batches: 4, consolidate: 30);

        self::assertNull(
            $this->forecasterAt('+20 seconds')->forecast(RecommendationRunReportModel::none(), $this->user),
        );
    }

    /**
     * History: an LLM run (10 s pickup + 4 × 10 + 30), a scoring run (15 s pickup + 3 × 25) and an LLM run that skipped
     * consolidation, whose phases look like a scoring run's. 4 scoring batches, 20 s in: 15 + 4 × 25 − 20.
     */
    public function testAScoringRunIsPredictedFromScoringRunsAlone(): void
    {
        $this->seedHistoricalRun(pickup: 10, batchWall: 40, batches: 4, consolidate: 30);
        $this->seedHistoricalScoringRun(pickup: 15, batchWall: 75, batches: 3);
        $this->seedHistoricalLlmRunWithoutConsolidation(pickup: 10, batchWall: 40, batches: 4);
        $eta = $this->forecasterAt('+20 seconds')
            ->forecast($this->liveScoringReportWithBatches(4), $this->user)?->etaSeconds;

        self::assertSame(95, $eta);
    }

    /**
     * 16 s of pickup, a 10 s wait, 11 s of batch waves over 5 batches and a 9 s finalize tick make 46 s.
     * 28 s into the next 5-batch run, 18 s remain, not the 0 the call time alone leaves.
     */
    public function testPredictsTheTimeBetweenCallsOnTheClockElapsedRunsOn(): void
    {
        $run = $this->fixtures->persistRunAt($this->user, new \DateTimeImmutable(self::HISTORY_START));
        $run->snapshot(RecommendationEngineKind::Scoring, ScoringProtocol::SystemOne, [[1]]);
        for ($batch = 1; $batch <= 5; $batch++) {
            $this->finishedLog($run, CallPhase::Batch, $batch, 26, 11);
        }
        $run->complete((new \DateTimeImmutable(self::HISTORY_START))->modify('+46 seconds'));
        $this->entityManager->flush();

        $eta = $this->forecasterAt('+28 seconds')
            ->forecast($this->liveScoringReportWithBatches(5), $this->user)?->etaSeconds;

        self::assertSame(18, $eta);
    }

    private function forecasterAt(string $offset): RecommendationRunForecaster
    {
        /** @var RecommendationRunTimingRepository $timings */
        $timings = self::getContainer()->get(RecommendationRunTimingRepository::class);

        return new RecommendationRunForecaster(
            $timings,
            new MockClock((new \DateTimeImmutable(self::RUN_START))->modify($offset)),
        );
    }

    private function liveReportWithBatches(int $batches, int $finished = 0): RecommendationRunReportModel
    {
        $run = new RecommendationRun($this->user, new \DateTimeImmutable(self::RUN_START));
        $run->snapshot(
            RecommendationEngineKind::Llm,
            null,
            array_map(static fn (int $index): array => [$index], range(1, $batches)),
        );
        $run->markFirstBatchStarted();
        for ($batch = 0; $batch < $finished; $batch++) {
            $run->recordBatchWinners([]);
        }

        return RecommendationRunReportModel::fromRun($run);
    }

    private function liveScoringReportWithBatches(int $batches): RecommendationRunReportModel
    {
        $run = new RecommendationRun($this->user, new \DateTimeImmutable(self::RUN_START));
        $run->snapshot(
            RecommendationEngineKind::Scoring,
            ScoringProtocol::SystemOne,
            array_map(static fn (int $index): array => [$index], range(1, $batches)),
        );
        $run->markFirstBatchStarted();

        return RecommendationRunReportModel::fromRun($run);
    }

    private function seedHistoricalRun(int $pickup, int $batchWall, int $batches, int $consolidate): void
    {
        $run = $this->runWithPickupAndBatches(RecommendationEngineKind::Llm, $pickup, $batchWall, $batches);
        $this->finishedLog($run, CallPhase::Consolidate, null, $pickup + $batchWall, $consolidate);
        $this->completeAfter($run, $pickup + $batchWall + $consolidate);
    }

    /** An LLM run whose pool was empty at consolidation: no consolidate row, so its phases are a scoring run's. */
    private function seedHistoricalLlmRunWithoutConsolidation(int $pickup, int $batchWall, int $batches): void
    {
        $run = $this->runWithPickupAndBatches(RecommendationEngineKind::Llm, $pickup, $batchWall, $batches);
        $this->completeAfter($run, $pickup + $batchWall);
    }

    private function seedHistoricalScoringRun(int $pickup, int $batchWall, int $batches): void
    {
        $run = $this->runWithPickupAndBatches(RecommendationEngineKind::Scoring, $pickup, $batchWall, $batches);
        $this->completeAfter($run, $pickup + $batchWall);
    }

    /** Its batches go out in one wave, $pickup seconds after it was created: the only time between its calls. */
    private function runWithPickupAndBatches(
        RecommendationEngineKind $engineKind,
        int $pickup,
        int $batchWall,
        int $batches,
    ): RecommendationRun {
        $run = $this->fixtures->persistRunAt($this->user, new \DateTimeImmutable(self::HISTORY_START));
        $run->snapshot($engineKind, null, [[1]]);

        for ($batch = 1; $batch <= $batches; $batch++) {
            $this->finishedLog($run, CallPhase::Batch, $batch, $pickup, $batchWall);
        }

        return $run;
    }

    private function completeAfter(RecommendationRun $run, int $seconds): void
    {
        $run->complete((new \DateTimeImmutable(self::HISTORY_START))->modify("+{$seconds} seconds"));
        $this->entityManager->flush();
    }

    private function finishedLog(
        RecommendationRun $run,
        CallPhase $phase,
        ?int $batchNumber,
        int $startOffset,
        int $spanSeconds,
    ): void {
        $base = new \DateTimeImmutable(self::HISTORY_START);
        $log = $this->fixtures->log($run, $phase, $batchNumber, 1, 'req', $base->modify("+{$startOffset} seconds"));
        $this->fixtures->settleLog($log, 'reply', new CallOutcome(
            CallVerdict::Usable,
            0,
            $base->modify('+' . ($startOffset + $spanSeconds) . ' seconds'),
            'stop',
        ));
    }
}
