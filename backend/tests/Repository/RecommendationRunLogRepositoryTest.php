<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CallOutcome;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Repository\Exception\RecordNotFoundException;
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
        $factory = new UserFactory($this->entityManager, $hasher);
        $this->user = $factory->create('log-owner@example.test');
        $this->otherUser = $factory->create('log-other@example.test');
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);
        $this->logs = $logs;
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    public function testListReturnsMetadataWithByteSizesButNoBodies(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $finished = $this->fixtures->log(
            $run,
            CallPhase::Batch,
            1,
            1,
            'req-body-a',
            new \DateTimeImmutable('2026-08-08T10:00:00Z'),
        );
        $this->fixtures->settleLog(
            $finished,
            'decoded text',
            new CallOutcome(
                CallVerdict::Usable,
                41_000,
                new \DateTimeImmutable('2026-08-08T10:00:05Z'),
                'stop',
            ),
        );
        $this->fixtures->log($run, CallPhase::Consolidate, null, 1, 'req-body-longer');
        $this->entityManager->flush();

        $rows = array_map(
            static fn (array $row): array => [
                ...$row,
                'createdAt' => $row['createdAt']->format(\DATE_ATOM),
                'finishedAt' => $row['finishedAt']?->format(\DATE_ATOM),
            ],
            $this->logs->listForRun($this->user, $run->requireId()),
        );

        self::assertSame(
            [
                [
                    'id' => $finished->getId(),
                    'runId' => $run->getId(),
                    'phase' => CallPhase::Batch,
                    'batchNumber' => 1,
                    'attempt' => 1,
                    'verdict' => CallVerdict::Usable,
                    'requestBytes' => \strlen('req-body-a'),
                    'responseBytes' => \strlen('decoded text'),
                    'wireBytes' => 41_000,
                    'createdAt' => (new \DateTimeImmutable('2026-08-08T10:00:00Z'))->format(\DATE_ATOM),
                    'finishedAt' => (new \DateTimeImmutable('2026-08-08T10:00:05Z'))->format(\DATE_ATOM),
                    'errorDetail' => null,
                    'finishReason' => 'stop',
                ],
                [
                    'id' => $rows[1]['id'],
                    'runId' => $run->getId(),
                    'phase' => CallPhase::Consolidate,
                    'batchNumber' => null,
                    'attempt' => 1,
                    'verdict' => null,
                    'requestBytes' => \strlen('req-body-longer'),
                    'responseBytes' => 0,
                    'wireBytes' => 0,
                    'createdAt' => (new \DateTimeImmutable('2026-08-08T10:00:00Z'))->format(\DATE_ATOM),
                    'finishedAt' => null,
                    'errorDetail' => null,
                    'finishReason' => null,
                ],
            ],
            $rows,
        );
    }

    public function testStreamingTextReturnsOnlyVerdictlessRows(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $done = $this->fixtures->log($run, CallPhase::Batch, 1, 1, 'r');
        $this->fixtures->settleLog(
            $done,
            'finished text',
            new CallOutcome(
                CallVerdict::Unusable,
                7,
                new \DateTimeImmutable('2026-08-08T10:00:05Z'),
                'length',
            ),
        );
        $streaming = $this->fixtures->log($run, CallPhase::Batch, 2, 1, 'r');
        $this->entityManager->flush();

        $streamingId = $streaming->getId();
        self::assertNotNull($streamingId);
        self::assertSame(
            [$streamingId => ''],
            $this->logs->streamingTextForRun($this->user, $run->requireId()),
        );
    }

    public function testCountAttemptsMatchesOnBatchNumberIsNullForTheConsolidationPhase(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $this->fixtures->log($run, CallPhase::Consolidate, null, 1, 'r');
        $this->fixtures->log($run, CallPhase::Consolidate, null, 2, 'r');
        $this->fixtures->log($run, CallPhase::Batch, 1, 1, 'r');
        $this->entityManager->flush();

        self::assertSame(2, $this->logs->countAttempts($run, CallPhase::Consolidate, null));
        self::assertSame(1, $this->logs->countAttempts($run, CallPhase::Batch, 1));
        self::assertSame(0, $this->logs->countAttempts($run, CallPhase::Batch, 2));
    }

    public function testCountAttemptsIsScopedToTheRunNotTheUser(): void
    {
        $earlierRun = $this->fixtures->createRun($this->user);
        $this->fixtures->log($earlierRun, CallPhase::Batch, 1, 1, 'r');
        $currentRun = $this->fixtures->createRun($this->user);
        $this->fixtures->log($currentRun, CallPhase::Batch, 1, 1, 'r');
        $this->entityManager->flush();

        self::assertSame(1, $this->logs->countAttempts($currentRun, CallPhase::Batch, 1));
    }

    public function testGetOneForUserReturnsTheCallersRow(): void
    {
        $mine = $this->fixtures->log(
            $this->fixtures->createRun($this->user),
            CallPhase::Batch,
            1,
            1,
            'r',
        );
        $this->entityManager->flush();
        $mineId = $mine->getId();
        self::assertNotNull($mineId);

        self::assertSame($mine, $this->logs->getOneForUser($this->user, $mineId));
    }

    public function testGetOneForUserRefusesAnotherUsersRow(): void
    {
        $theirs = $this->fixtures->log(
            $this->fixtures->createRun($this->otherUser),
            CallPhase::Batch,
            1,
            1,
            'r',
        );
        $this->entityManager->flush();
        $theirsId = $theirs->getId();
        self::assertNotNull($theirsId);

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such debug log entry.');

        $this->logs->getOneForUser($this->user, $theirsId);
    }

    public function testDeleteForUserLeavesOtherUsersRows(): void
    {
        $run = $this->fixtures->createRun($this->user);
        $this->fixtures->log($run, CallPhase::Batch, 1, 1, 'r');
        $otherRun = $this->fixtures->createRun($this->otherUser);
        $kept = $this->fixtures->log($otherRun, CallPhase::Batch, 1, 1, 'r');
        $this->entityManager->flush();
        $keptId = $kept->getId();
        self::assertNotNull($keptId);

        $this->logs->deleteForUser($this->user);

        // Bulk DQL bypasses the identity map: clear before asserting survival,
        // or find() serves the stale in-memory row (see the #237 lesson).
        $this->entityManager->clear();
        self::assertSame([], $this->logs->listForRun($this->user, $run->requireId()));
        self::assertNotNull($this->entityManager->find(RecommendationRunLog::class, $keptId));
    }
}
