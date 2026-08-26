<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Repository\RecommendationRunTimingRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\RecommendationEtaEstimator;
use App\Service\Recommendation\RecommendationRunReport;
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

    public function testReturnsNullWhenNoRunIsInFlight(): void
    {
        $this->seedHistoricalRun(distill: 10, batchWall: 40, batches: 4, consolidate: 30);

        self::assertNull(
            $this->estimatorAt('+20 seconds')->estimateSeconds(RecommendationRunReport::none(), $this->user),
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

    private function liveReportWithBatches(int $batches): RecommendationRunReport
    {
        $run = new RecommendationRun($this->user, new \DateTimeImmutable(self::RUN_START));
        $run->snapshot(array_map(static fn (int $i): array => [$i], range(1, $batches)));

        return RecommendationRunReport::fromRun($run);
    }

    private function seedHistoricalRun(int $distill, int $batchWall, int $batches, int $consolidate): void
    {
        $run = $this->fixtures->createRun($this->user);
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));

        $this->finishedLog($run, RecommendationRunLog::PHASE_DISTILL, null, 0, $distill);
        for ($batch = 1; $batch <= $batches; $batch++) {
            $this->finishedLog($run, RecommendationRunLog::PHASE_BATCH, $batch, 0, $batchWall);
        }
        $this->finishedLog($run, RecommendationRunLog::PHASE_CONSOLIDATE, null, 0, $consolidate);
        $this->em->flush();
    }

    private function finishedLog(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        int $startOffset,
        int $spanSeconds,
    ): void {
        $base = new \DateTimeImmutable('2026-08-07T09:00:00Z');
        $this->fixtures->log($run, $phase, $batchNumber, 1, 'req', $base->modify("+{$startOffset} seconds"))
            ->finish('reply', RecommendationRunLog::VERDICT_USABLE, 0, 'stop', $base->modify(
                '+' . ($startOffset + $spanSeconds) . ' seconds',
            ));
    }
}
