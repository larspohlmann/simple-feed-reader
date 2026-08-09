<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Recommendation\Exception\NoResumableRecommendationRunException;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Against the real repository and entity manager, not mocks: start()'s job is
 * to decide between three existing-run states (none, active, failed) and a
 * mock would have to encode that decision itself instead of proving it.
 */
final class RecommendationRunStarterTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->em, $hasher))->create('run-starter@example.test');
    }

    public function testNotConfiguredThrows(): void
    {
        $this->expectException(AiNotConfiguredException::class);

        $this->starter()->start($this->user);
    }

    public function testFirstStartPersistsAPendingRun(): void
    {
        $this->seedReadyAiSettings($this->user);

        $report = $this->starter()->start($this->user);

        self::assertSame('pending', $report->status);
        self::assertNotNull($this->runs()->findActiveForUser($this->user));
    }

    public function testSecondStartWhileActiveReturnsTheSameRun(): void
    {
        $this->seedReadyAiSettings($this->user);

        $this->starter()->start($this->user);
        $firstRun = $this->runs()->findActiveForUser($this->user);
        self::assertNotNull($firstRun);

        $this->starter()->start($this->user);
        $secondRun = $this->runs()->findActiveForUser($this->user);

        self::assertSame($firstRun->getId(), $secondRun?->getId());
        self::assertSame(1, $this->countRuns());
    }

    public function testStartAlwaysBeginsAFreshRunEvenWithAFailedRunPresent(): void
    {
        $this->seedReadyAiSettings($this->user);
        $failed = $this->persistFailedRunWithOneWinner();

        // start() no longer resumes: whether to pick up a failed run is the
        // user's choice, made in the client, so start() always begins fresh
        // and resume() is its own action (#329).
        $report = $this->starter()->start($this->user);

        self::assertSame('pending', $report->status);
        self::assertSame(0, $report->batchesDone);
        self::assertSame(2, $this->countRuns());
        $active = $this->runs()->findActiveForUser($this->user);
        self::assertNotNull($active);
        self::assertNotSame($failed->getId(), $active->getId());
    }

    public function testResumeContinuesTheLatestFailedRunInPlace(): void
    {
        $this->seedReadyAiSettings($this->user);
        $failed = $this->persistFailedRunWithOneWinner();

        $report = $this->starter()->resume($this->user);

        self::assertSame('running', $report->status);
        self::assertNull($report->error);
        self::assertSame(1, $report->batchesDone);
        self::assertSame($failed->getId(), $this->runs()->findActiveForUser($this->user)?->getId());
        self::assertSame(1, $this->countRuns());
    }

    public function testResumeWithNoFailedRunThrows(): void
    {
        $this->seedReadyAiSettings($this->user);

        $this->expectException(NoResumableRecommendationRunException::class);

        $this->starter()->resume($this->user);
    }

    public function testANewRunWipesTheDebugLogOfThePreviousRun(): void
    {
        $this->seedReadyAiSettings($this->user);
        $previous = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-08T09:00:00Z'));
        $previous->snapshot([]);
        $previous->complete(new \DateTimeImmutable('2026-08-08T09:01:00Z'));
        $this->em->persist($previous);
        $this->em->persist(new RecommendationRunLog(
            $previous,
            RecommendationRunLog::PHASE_BATCH,
            1,
            1,
            'old request',
            new \DateTimeImmutable('2026-08-08T09:00:30Z'),
        ));
        $this->em->flush();

        $this->starter()->start($this->user);

        self::assertSame([], $this->runLogs()->listForUser($this->user));
    }

    public function testResumingAFailedRunKeepsItsDebugLog(): void
    {
        $this->seedReadyAiSettings($this->user);
        $failed = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-08T09:00:00Z'));
        $failed->snapshot([[1], [2]]);
        $failed->fail('provider gone', new \DateTimeImmutable('2026-08-08T09:01:00Z'));
        $this->em->persist($failed);
        $this->em->persist(new RecommendationRunLog(
            $failed,
            RecommendationRunLog::PHASE_BATCH,
            1,
            1,
            'kept request',
            new \DateTimeImmutable('2026-08-08T09:00:30Z'),
        ));
        $this->em->flush();

        $report = $this->starter()->resume($this->user);

        self::assertSame(RecommendationRun::STATUS_RUNNING, $report->status);
        // The wipe is bulk DQL when it runs, so clear before asserting survival.
        $this->em->clear();
        self::assertCount(1, $this->runLogs()->listForUser($this->user));
    }

    private function runLogs(): RecommendationRunLogRepository
    {
        /** @var RecommendationRunLogRepository $repository */
        $repository = self::getContainer()->get(RecommendationRunLogRepository::class);

        return $repository;
    }

    private function seedReadyAiSettings(User $user): void
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $sealed = $cipher->seal($userId, 'sk-throwaway1234');
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');

        $settings = new AiProviderSettings($user, null, 'https://api.example.test/v1', $sealed, '1234', $now);
        $this->em->persist($settings);
        $settings->chooseModel('m', $now, 32768);
        $user->setActiveAiProviderSettings($settings);
        $this->em->flush();
    }

    /** A failed run with one batch already ranked, so resume() has a partial
     *  result to continue from and start() has a run to leave as history. */
    private function persistFailedRunWithOneWinner(): RecommendationRun
    {
        $failed = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-07 09:00:00'));
        $failed->snapshot([[1, 2], [3]]);
        $failed->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);
        $failed->fail('provider unreachable', new \DateTimeImmutable('2026-08-07 09:05:00'));
        $this->em->persist($failed);
        $this->em->flush();

        return $failed;
    }

    private function countRuns(): int
    {
        /** @var int $count */
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(RecommendationRun::class, 'r')
            ->getQuery()
            ->getSingleScalarResult();

        return $count;
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function starter(): RecommendationRunStarter
    {
        /** @var RecommendationRunStarter $starter */
        $starter = self::getContainer()->get(RecommendationRunStarter::class);

        return $starter;
    }
}
