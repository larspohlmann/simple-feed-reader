<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CallOutcome;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Repository\RecommendationRunTimingRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260925090000;
use Psr\Log\NullLogger;
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
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('timing-owner@example.test');

        /** @var RecommendationRunTimingRepository $timings */
        $timings = self::getContainer()->get(RecommendationRunTimingRepository::class);
        $this->timings = $timings;

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    public function testReturnsEachPhaseWallSpanWithBatchCount(): void
    {
        $run = $this->completedRun();
        // Distill 10s.
        $this->finishedLog($run, CallPhase::Distill, null, '10:00:00', '10:00:10');
        // Two batches running concurrently: the phase wall span is 10:00:10 →
        // 10:00:40 = 30s over 2 distinct batch numbers.
        $this->finishedLog($run, CallPhase::Batch, 1, '10:00:10', '10:00:40');
        $this->finishedLog($run, CallPhase::Batch, 2, '10:00:12', '10:00:30');
        // Consolidate 30s.
        $this->finishedLog($run, CallPhase::Consolidate, null, '10:00:40', '10:01:10');
        $this->entityManager->flush();

        $spans = array_map(
            static fn (array $span): array => [...$span, 'phase' => $span['phase']->value],
            $this->timings->completedRunPhaseSpans($this->user, 10),
        );

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
        $this->finishedLog($running, CallPhase::Distill, null, '10:00:00', '10:00:05');

        // Another user's completed run.
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stranger = (new UserFactory($this->entityManager, $hasher))->create('timing-stranger@example.test');
        $strangerRun = $this->completedRun($stranger);
        $this->finishedLog($strangerRun, CallPhase::Distill, null, '10:00:00', '10:00:05');

        // Two completed runs of this user, but the limit is 1 → only the newest.
        $older = $this->completedRun();
        $this->finishedLog($older, CallPhase::Distill, null, '10:00:00', '10:00:05');
        $newer = $this->completedRun();
        $this->finishedLog($newer, CallPhase::Distill, null, '10:00:00', '10:00:09');
        $this->entityManager->flush();

        $spans = $this->timings->completedRunPhaseSpans($this->user, 1);

        self::assertSame([$newer->getId()], array_values(array_unique(array_column($spans, 'runId'))));
        self::assertSame(9.0, $spans[0]['spanSeconds']);
    }

    public function testDeletingRetiredDedupRowsRestoresTheEtaRead(): void
    {
        $run = $this->completedRun();
        $this->finishedLog($run, CallPhase::Distill, null, '10:00:00', '10:00:10');
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        $connection->insert('recommendation_run_log', [
            'run_id' => $run->requireId(),
            'phase' => 'dedup',
            'attempt' => 1,
            'request_body' => 'dedup-req',
            'response_text' => 'dedup-resp',
            'wire_bytes' => 0,
            'created_at' => '2026-08-08 10:00:20',
            'finished_at' => '2026-08-08 10:00:25',
        ]);
        $this->entityManager->clear();

        try {
            $this->timings->completedRunPhaseSpans($this->user, 10);
            self::fail('Expected a ValueError while the dedup row survives.');
        } catch (\ValueError $exception) {
            self::assertStringContainsString('dedup', $exception->getMessage());
        }

        // Migration classes are deliberately excluded from Composer's
        // autoloader (see doctrine_migrations.yaml), so this test loads the
        // one file it needs to drive directly.
        require_once dirname(__DIR__, 2) . '/migrations/Version20260925090000.php';
        $migration = new Version20260925090000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        $this->entityManager->clear();

        $spans = array_map(
            static fn (array $span): array => [...$span, 'phase' => $span['phase']->value],
            $this->timings->completedRunPhaseSpans($this->user, 10),
        );

        self::assertSame(
            [['runId' => $run->getId(), 'phase' => 'distill', 'spanSeconds' => 10.0, 'batchCount' => 0]],
            $spans,
        );
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
        CallPhase $phase,
        ?int $batchNumber,
        string $startedAt,
        string $finishedAt,
    ): void {
        $log = $this->fixtures->log(
            $run,
            $phase,
            $batchNumber,
            1,
            'req',
            new \DateTimeImmutable('2026-08-08T' . $startedAt . 'Z'),
        );
        $this->fixtures->settleLog(
            $log,
            'reply',
            new CallOutcome(
                CallVerdict::Usable,
                0,
                new \DateTimeImmutable('2026-08-08T' . $finishedAt . 'Z'),
                'stop',
            ),
        );
    }
}
