<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\CallOutcome;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Enum\RecommendationEngineKind;
use App\Repository\RecommendationRunTimingRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\RecommendationEtaEstimator;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationEtaEstimatorTest extends DbTestCase
{
    private const string RUN_START = '2026-08-08T12:00:00Z';

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
        // History: distill 10s, batch phase 40s over 4 batches (10s/batch),
        // consolidate 30s. A live run with 3 batches is predicted to take
        // 10 + 3×10 + 30 = 70s; 20s in, 50s remain.
        $this->seedHistoricalRun(distill: 10, batchWall: 40, batches: 4, consolidate: 30);
        $report = $this->liveReportWithBatches(3);

        $eta = $this->estimatorAt('+20 seconds')->estimateSeconds($report, $this->user);

        self::assertSame(50, $eta);
    }

    public function testPinsAtZeroOnceElapsedPassesThePrediction(): void
    {
        $this->seedHistoricalRun(distill: 10, batchWall: 40, batches: 4, consolidate: 30);
        $report = $this->liveReportWithBatches(3); // predicted 70s

        $eta = $this->estimatorAt('+200 seconds')->estimateSeconds($report, $this->user);

        self::assertSame(0, $eta);
    }

    /** 40 s over 3 batches is 13.3 s a batch: one batch leaves 33.3 s, two leave 46.7 s, 20 s in. */
    public function testRoundsTheRemainingSecondsToTheNearest(): void
    {
        $this->seedHistoricalRun(distill: 10, batchWall: 40, batches: 3, consolidate: 30);
        $estimator = $this->estimatorAt('+20 seconds');

        self::assertSame(33, $estimator->estimateSeconds($this->liveReportWithBatches(1), $this->user));
        self::assertSame(47, $estimator->estimateSeconds($this->liveReportWithBatches(2), $this->user));
    }

    public function testReturnsNullWithoutAnyCompletedHistory(): void
    {
        $report = $this->liveReportWithBatches(3);

        self::assertNull($this->estimatorAt('+20 seconds')->estimateSeconds($report, $this->user));
    }

    public function testReturnsNullBeforeTheFirstBatchStarts(): void
    {
        $this->seedHistoricalRun(distill: 10, batchWall: 40, batches: 4, consolidate: 30);
        $run = new RecommendationRun($this->user, new \DateTimeImmutable(self::RUN_START));
        $run->snapshot(RecommendationEngineKind::Llm, [[1], [2], [3]]);

        $eta = $this->estimatorAt('+20 seconds')->estimateSeconds(
            RecommendationRunReportModel::fromRun($run),
            $this->user,
        );

        self::assertNull($eta);
    }

    public function testReturnsNullWhenNoRunIsInFlight(): void
    {
        $this->seedHistoricalRun(distill: 10, batchWall: 40, batches: 4, consolidate: 30);

        self::assertNull(
            $this->estimatorAt('+20 seconds')->estimateSeconds(RecommendationRunReportModel::none(), $this->user),
        );
    }

    /**
     * History: an LLM run (10 + 4 × 10 + 30), a Jev run (15 + 3 × 25) and an LLM run that skipped consolidation, whose
     * phases look like Jev's. 4 Jev batches, 20 s in: 15 + 4 × 25 − 20.
     */
    public function testAJevRunIsPredictedFromJevRunsAlone(): void
    {
        $this->seedHistoricalRun(distill: 10, batchWall: 40, batches: 4, consolidate: 30);
        $this->seedHistoricalJevRun(distill: 15, batchWall: 75, batches: 3);
        $this->seedHistoricalLlmRunWithoutConsolidation(distill: 10, batchWall: 40, batches: 4);
        $run = new RecommendationRun($this->user, new \DateTimeImmutable(self::RUN_START));
        $run->snapshot(RecommendationEngineKind::Jev, [[1], [2], [3], [4]]);
        $run->markFirstBatchStarted();

        $eta = $this->estimatorAt('+20 seconds')->estimateSeconds(
            RecommendationRunReportModel::fromRun($run),
            $this->user,
        );

        self::assertSame(95, $eta);
    }

    private function estimatorAt(string $offset): RecommendationEtaEstimator
    {
        /** @var RecommendationRunTimingRepository $timings */
        $timings = self::getContainer()->get(RecommendationRunTimingRepository::class);

        return new RecommendationEtaEstimator(
            $timings,
            new MockClock((new \DateTimeImmutable(self::RUN_START))->modify($offset)),
        );
    }

    private function liveReportWithBatches(int $batches): RecommendationRunReportModel
    {
        $run = new RecommendationRun($this->user, new \DateTimeImmutable(self::RUN_START));
        $run->snapshot(
            RecommendationEngineKind::Llm,
            array_map(static fn (int $index): array => [$index], range(1, $batches)),
        );
        $run->markFirstBatchStarted();

        return RecommendationRunReportModel::fromRun($run);
    }

    private function seedHistoricalRun(int $distill, int $batchWall, int $batches, int $consolidate): void
    {
        $run = $this->completedRunWithDistillationAndBatches(
            RecommendationEngineKind::Llm,
            $distill,
            $batchWall,
            $batches,
        );
        $this->finishedLog($run, CallPhase::Consolidate, null, 0, $consolidate);
        $this->entityManager->flush();
    }

    /** An LLM run whose pool was empty at consolidation: no consolidate row, so its phases are Jev's. */
    private function seedHistoricalLlmRunWithoutConsolidation(int $distill, int $batchWall, int $batches): void
    {
        $this->completedRunWithDistillationAndBatches(RecommendationEngineKind::Llm, $distill, $batchWall, $batches);
        $this->entityManager->flush();
    }

    private function seedHistoricalJevRun(int $distill, int $batchWall, int $batches): void
    {
        $this->completedRunWithDistillationAndBatches(RecommendationEngineKind::Jev, $distill, $batchWall, $batches);
        $this->entityManager->flush();
    }

    private function completedRunWithDistillationAndBatches(
        RecommendationEngineKind $engineKind,
        int $distill,
        int $batchWall,
        int $batches,
    ): RecommendationRun {
        $run = $this->fixtures->createRun($this->user);
        $run->snapshot($engineKind, [[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));

        $this->finishedLog($run, CallPhase::Distill, null, 0, $distill);
        for ($batch = 1; $batch <= $batches; $batch++) {
            $this->finishedLog($run, CallPhase::Batch, $batch, 0, $batchWall);
        }

        return $run;
    }

    private function finishedLog(
        RecommendationRun $run,
        CallPhase $phase,
        ?int $batchNumber,
        int $startOffset,
        int $spanSeconds,
    ): void {
        $base = new \DateTimeImmutable('2026-08-07T09:00:00Z');
        $log = $this->fixtures->log($run, $phase, $batchNumber, 1, 'req', $base->modify("+{$startOffset} seconds"));
        $this->fixtures->settleLog($log, 'reply', new CallOutcome(
            CallVerdict::Usable,
            0,
            $base->modify('+' . ($startOffset + $spanSeconds) . ' seconds'),
            'stop',
        ));
    }
}
