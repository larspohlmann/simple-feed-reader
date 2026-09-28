<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run;

use App\Entity\CallOutcome;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
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
        $this->user = (new UserFactory($this->em, $hasher))->create('eta-owner@example.test');

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
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

    public function testReturnsNullWithoutAnyCompletedHistory(): void
    {
        $report = $this->liveReportWithBatches(3);

        self::assertNull($this->estimatorAt('+20 seconds')->estimateSeconds($report, $this->user));
    }

    public function testReturnsNullBeforeTheFirstBatchStarts(): void
    {
        $this->seedHistoricalRun(distill: 10, batchWall: 40, batches: 4, consolidate: 30);
        $run = new RecommendationRun($this->user, new \DateTimeImmutable(self::RUN_START));
        $run->snapshot([[1], [2], [3]]);

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
        $run->snapshot(array_map(static fn (int $i): array => [$i], range(1, $batches)));
        $run->markFirstBatchStarted();

        return RecommendationRunReportModel::fromRun($run);
    }

    private function seedHistoricalRun(int $distill, int $batchWall, int $batches, int $consolidate): void
    {
        $run = $this->fixtures->createRun($this->user);
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));

        $this->finishedLog($run, CallPhase::Distill, null, 0, $distill);
        for ($batch = 1; $batch <= $batches; $batch++) {
            $this->finishedLog($run, CallPhase::Batch, $batch, 0, $batchWall);
        }
        $this->finishedLog($run, CallPhase::Consolidate, null, 0, $consolidate);
        $this->em->flush();
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
