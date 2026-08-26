<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Repository\RecommendationRunTimingRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationRunTimingRepositoryTest extends DbTestCase
{
    private User $user;
    private RecommendationRunTimingRepository $timings;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->em, $hasher))->create('timing-owner@example.test');

        /** @var RecommendationRunTimingRepository $timings */
        $timings = self::getContainer()->get(RecommendationRunTimingRepository::class);
        $this->timings = $timings;

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    public function testReturnsEachPhaseWallSpanWithBatchCount(): void
    {
        $run = $this->completedRun();
        // Distill 10s.
        $this->finishedLog($run, RecommendationRunLog::PHASE_DISTILL, null, '10:00:00', '10:00:10');
        // Two batches running concurrently: the phase wall span is 10:00:10 →
        // 10:00:40 = 30s over 2 distinct batch numbers.
        $this->finishedLog($run, RecommendationRunLog::PHASE_BATCH, 1, '10:00:10', '10:00:40');
        $this->finishedLog($run, RecommendationRunLog::PHASE_BATCH, 2, '10:00:12', '10:00:30');
        // Consolidate 30s.
        $this->finishedLog($run, RecommendationRunLog::PHASE_CONSOLIDATE, null, '10:00:40', '10:01:10');
        $this->em->flush();

        $spans = $this->timings->completedRunPhaseSpans($this->user, 10);

        $runId = $run->getId();
        self::assertEqualsCanonicalizing([
            ['runId' => $runId, 'phase' => 'distill', 'spanSeconds' => 10.0, 'batchCount' => 0],
            ['runId' => $runId, 'phase' => 'batch', 'spanSeconds' => 30.0, 'batchCount' => 2],
            ['runId' => $runId, 'phase' => 'consolidate', 'spanSeconds' => 30.0, 'batchCount' => 0],
        ], $spans);
    }

    public function testIgnoresRunningRunsOtherUsersAndRunsBeyondTheLimit(): void
    {
        // A running run of this user: no completed status, so excluded.
        $running = $this->fixtures->createRun($this->user);
        $running->snapshot([[1]]);
        $this->finishedLog($running, RecommendationRunLog::PHASE_DISTILL, null, '10:00:00', '10:00:05');

        // Another user's completed run.
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stranger = (new UserFactory($this->em, $hasher))->create('timing-stranger@example.test');
        $strangerRun = $this->completedRun($stranger);
        $this->finishedLog($strangerRun, RecommendationRunLog::PHASE_DISTILL, null, '10:00:00', '10:00:05');

        // Two completed runs of this user, but the limit is 1 → only the newest.
        $older = $this->completedRun();
        $this->finishedLog($older, RecommendationRunLog::PHASE_DISTILL, null, '10:00:00', '10:00:05');
        $newer = $this->completedRun();
        $this->finishedLog($newer, RecommendationRunLog::PHASE_DISTILL, null, '10:00:00', '10:00:09');
        $this->em->flush();

        $spans = $this->timings->completedRunPhaseSpans($this->user, 1);

        self::assertSame([$newer->getId()], array_values(array_unique(array_column($spans, 'runId'))));
        self::assertSame(9.0, $spans[0]['spanSeconds']);
    }

    private function completedRun(?User $user = null): RecommendationRun
    {
        $run = $this->fixtures->createRun($user ?? $this->user);
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-08T11:00:00Z'));

        return $run;
    }

    private function finishedLog(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        string $startedAt,
        string $finishedAt,
    ): void {
        $this->fixtures->log(
            $run,
            $phase,
            $batchNumber,
            1,
            'req',
            new \DateTimeImmutable('2026-08-08T' . $startedAt . 'Z'),
        )->finish(
            'reply',
            RecommendationRunLog::VERDICT_USABLE,
            0,
            'stop',
            new \DateTimeImmutable('2026-08-08T' . $finishedAt . 'Z'),
        );
    }
}
