<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Repository\RecommendationRunLogRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationRunLogRepositoryTest extends DbTestCase
{
    private User $user;
    private User $otherUser;
    private RecommendationRunLogRepository $logs;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $factory = new UserFactory($this->em, $hasher);
        $this->user = $factory->create('log-owner@example.test');
        $this->otherUser = $factory->create('log-other@example.test');
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);
        $this->logs = $logs;
    }

    public function testListReturnsMetadataWithByteSizesButNoBodies(): void
    {
        $run = $this->createRun($this->user);
        $finished = $this->log($run, RecommendationRunLog::PHASE_BATCH, 1, 1, 'req-body-a');
        $finished->finish(
            'decoded text',
            RecommendationRunLog::VERDICT_USABLE,
            new \DateTimeImmutable('2026-08-08T10:00:05Z'),
        );
        $this->log($run, RecommendationRunLog::PHASE_DEDUP, null, 1, 'req-body-longer');
        $this->em->flush();

        $rows = $this->logs->listForUser($this->user);

        self::assertSame(
            [
                [
                    'id' => $finished->getId(),
                    'phase' => 'batch',
                    'batchNumber' => 1,
                    'attempt' => 1,
                    'verdict' => 'usable',
                    'requestBytes' => \strlen('req-body-a'),
                    'responseBytes' => \strlen('decoded text'),
                ],
                [
                    'id' => $rows[1]['id'],
                    'phase' => 'dedup',
                    'batchNumber' => null,
                    'attempt' => 1,
                    'verdict' => null,
                    'requestBytes' => \strlen('req-body-longer'),
                    'responseBytes' => 0,
                ],
            ],
            $rows,
        );
    }

    public function testStreamingTextReturnsOnlyVerdictlessRows(): void
    {
        $run = $this->createRun($this->user);
        $done = $this->log($run, RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $done->finish(
            'finished text',
            RecommendationRunLog::VERDICT_UNUSABLE,
            new \DateTimeImmutable('2026-08-08T10:00:05Z'),
        );
        $streaming = $this->log($run, RecommendationRunLog::PHASE_BATCH, 2, 1, 'r');
        $this->em->flush();

        $streamingId = $streaming->getId();
        self::assertNotNull($streamingId);
        self::assertSame([$streamingId => ''], $this->logs->streamingTextForUser($this->user));
    }

    public function testFindOwnedRefusesAnotherUsersRow(): void
    {
        $mine = $this->log($this->createRun($this->user), RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $theirs = $this->log($this->createRun($this->otherUser), RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $this->em->flush();
        $mineId = $mine->getId();
        $theirsId = $theirs->getId();
        self::assertNotNull($mineId);
        self::assertNotNull($theirsId);

        self::assertSame($mine, $this->logs->findOwned($mineId, $this->user));
        self::assertNull($this->logs->findOwned($theirsId, $this->user));
    }

    public function testDeleteForUserLeavesOtherUsersRows(): void
    {
        $this->log($this->createRun($this->user), RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $kept = $this->log($this->createRun($this->otherUser), RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $this->em->flush();
        $keptId = $kept->getId();
        self::assertNotNull($keptId);

        $this->logs->deleteForUser($this->user);

        // Bulk DQL bypasses the identity map: clear before asserting survival,
        // or find() serves the stale in-memory row (see the #237 lesson).
        $this->em->clear();
        self::assertSame([], $this->logs->listForUser($this->user));
        self::assertNotNull($this->em->find(RecommendationRunLog::class, $keptId));
    }

    private function createRun(User $user): RecommendationRun
    {
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-08T10:00:00Z'));
        $this->em->persist($run);

        return $run;
    }

    private function log(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        int $attempt,
        string $requestBody,
    ): RecommendationRunLog {
        $log = new RecommendationRunLog(
            $run,
            $phase,
            $batchNumber,
            $attempt,
            $requestBody,
            new \DateTimeImmutable('2026-08-08T10:00:01Z'),
        );
        $this->em->persist($log);

        return $log;
    }
}
