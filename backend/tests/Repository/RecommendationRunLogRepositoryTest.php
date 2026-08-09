<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationRunLogRepositoryTest extends DbTestCase
{
    private User $user;
    private User $otherUser;
    private RecommendationRunLogRepository $logs;
    private RecommendationRunFixtures $fixtures;

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
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    public function testListReturnsMetadataWithByteSizesButNoBodies(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $finished = $this->fixtures->log(
            $run,
            RecommendationRunLog::PHASE_BATCH,
            1,
            1,
            'req-body-a',
            new \DateTimeImmutable('2026-08-08T10:00:00Z'),
        );
        $finished->finish(
            'decoded text',
            RecommendationRunLog::VERDICT_USABLE,
            41_000,
            new \DateTimeImmutable('2026-08-08T10:00:05Z'),
        );
        $this->fixtures->log($run, RecommendationRunLog::PHASE_DEDUP, null, 1, 'req-body-longer');
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
                    'wireBytes' => 41_000,
                    'createdAt' => (new \DateTimeImmutable('2026-08-08T10:00:00Z'))->format(\DATE_ATOM),
                    'finishedAt' => (new \DateTimeImmutable('2026-08-08T10:00:05Z'))->format(\DATE_ATOM),
                    'errorDetail' => null,
                ],
                [
                    'id' => $rows[1]['id'],
                    'phase' => 'dedup',
                    'batchNumber' => null,
                    'attempt' => 1,
                    'verdict' => null,
                    'requestBytes' => \strlen('req-body-longer'),
                    'responseBytes' => 0,
                    'wireBytes' => 0,
                    'createdAt' => (new \DateTimeImmutable('2026-08-08T10:00:00Z'))->format(\DATE_ATOM),
                    'finishedAt' => null,
                    'errorDetail' => null,
                ],
            ],
            $rows,
        );
    }

    public function testStreamingTextReturnsOnlyVerdictlessRows(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $done = $this->fixtures->log($run, RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $done->finish(
            'finished text',
            RecommendationRunLog::VERDICT_UNUSABLE,
            7,
            new \DateTimeImmutable('2026-08-08T10:00:05Z'),
        );
        $streaming = $this->fixtures->log($run, RecommendationRunLog::PHASE_BATCH, 2, 1, 'r');
        $this->em->flush();

        $streamingId = $streaming->getId();
        self::assertNotNull($streamingId);
        self::assertSame([$streamingId => ''], $this->logs->streamingTextForUser($this->user));
    }

    public function testCountAttemptsMatchesOnBatchNumberIsNullForTheDedupPhase(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $this->fixtures->log($run, RecommendationRunLog::PHASE_DEDUP, null, 1, 'r');
        $this->fixtures->log($run, RecommendationRunLog::PHASE_DEDUP, null, 2, 'r');
        $this->fixtures->log($run, RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $this->em->flush();

        self::assertSame(2, $this->logs->countAttempts($run, RecommendationRunLog::PHASE_DEDUP, null));
        self::assertSame(1, $this->logs->countAttempts($run, RecommendationRunLog::PHASE_BATCH, 1));
        self::assertSame(0, $this->logs->countAttempts($run, RecommendationRunLog::PHASE_BATCH, 2));
    }

    public function testCountAttemptsIsScopedToTheRunNotTheUser(): void
    {
        $earlierRun = $this->fixtures->createRun($this->user);
        $this->fixtures->log($earlierRun, RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $currentRun = $this->fixtures->createRun($this->user);
        $this->fixtures->log($currentRun, RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $this->em->flush();

        self::assertSame(1, $this->logs->countAttempts($currentRun, RecommendationRunLog::PHASE_BATCH, 1));
    }

    public function testFindOwnedRefusesAnotherUsersRow(): void
    {
        $myRun = $this->fixtures->createRun($this->user);
        $mine = $this->fixtures->log($myRun, RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $theirRun = $this->fixtures->createRun($this->otherUser);
        $theirs = $this->fixtures->log($theirRun, RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
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
        $this->fixtures->log($this->fixtures->createRun($this->user), RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $otherRun = $this->fixtures->createRun($this->otherUser);
        $kept = $this->fixtures->log($otherRun, RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
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
}
