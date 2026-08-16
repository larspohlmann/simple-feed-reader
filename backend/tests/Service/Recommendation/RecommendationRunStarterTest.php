<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Process\DetachedProcessLauncherInterface;
use App\Service\Recommendation\Exception\NoResumableRecommendationRunException;
use App\Service\Recommendation\RecommendationDrainSpawner;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Worker\RecommendationDriverKind;
use App\Service\Worker\WorkerPresence;
use App\Tests\DbTestCase;
use App\Tests\Support\RecordingProcessLauncher;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\ClockInterface;
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
        $failed = $this->failedRunFor($this->user);

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
        $failed = $this->failedRunFor($this->user);

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

    public function testStampsTheProviderAndModelOnANewRun(): void
    {
        $user = $this->userWithProvider('https://openrouter.ai/api/v1', 'x-ai/grok-4-fast');

        $report = $this->starter()->start($user);

        $run = $this->runs()->findActiveForUser($user);
        self::assertNotNull($run);
        self::assertSame('openrouter.ai', $run->getProviderHost());
        self::assertSame('x-ai/grok-4-fast', $run->getModel());
        self::assertSame(RecommendationRun::STATUS_PENDING, $report->status);
    }

    public function testRestampsAResumedRunWithTheProviderItWillNowCall(): void
    {
        $user = $this->userWithProvider('https://openrouter.ai/api/v1', 'x-ai/grok-4-fast');
        $failed = $this->failedRunFor($user);
        $failed->stampProvider('openrouter.ai', 'x-ai/grok-4-fast');
        $this->switchProvider($user, 'http://localhost:1234/v1', 'bonsai-27b');

        $this->starter()->resume($user);

        self::assertSame('localhost', $failed->getProviderHost());
        self::assertSame('bonsai-27b', $failed->getModel());
    }

    public function testStampsNoHostWhenTheBaseUrlHasNone(): void
    {
        $user = $this->userWithProvider('not a url', 'some-model');

        $this->starter()->start($user);

        self::assertNull($this->runs()->findActiveForUser($user)?->getProviderHost());
    }

    /**
     * The window used to be one run wide, so this asserted the opposite: a new
     * run wiped what the last one recorded. Prompt regressions are only
     * visible as a difference between runs, so the previous run's log is
     * exactly what the next investigation needs (#401).
     */
    public function testANewRunKeepsThePreviousRunsDebugLog(): void
    {
        $this->seedReadyAiSettings($this->user);
        $previous = $this->seedCompletedRunWithLog('old request', 1);
        $this->em->flush();

        $this->starter()->start($this->user);

        self::assertSame([$previous->getId()], $this->runIdsHoldingLogs());
    }

    /**
     * Eleven runs, each with a log row, then a twelfth starts. The window
     * counts the new run too, so the two oldest lose their rows and nine
     * seeded runs keep theirs.
     */
    public function testOnlyTheNewestRunsInsideTheWindowKeepTheirDebugLog(): void
    {
        $this->seedReadyAiSettings($this->user);
        $seeded = [];
        foreach (range(1, 11) as $index) {
            $seeded[] = $this->seedCompletedRunWithLog('request ' . $index, $index);
        }
        $this->em->flush();

        $this->starter()->start($this->user);

        $survivingRunIds = $this->runIdsHoldingLogs();
        $expected = array_map(
            static fn (RecommendationRun $run): int => $run->getId() ?? 0,
            \array_slice($seeded, 2),
        );

        self::assertSame($expected, $survivingRunIds);
    }

    private function seedCompletedRunWithLog(string $requestBody, int $minute): RecommendationRun
    {
        $startedAt = new \DateTimeImmutable(\sprintf('2026-08-08T09:%02d:00Z', $minute));
        $run = new RecommendationRun($this->user, $startedAt);
        $run->snapshot([]);
        $run->complete($startedAt->modify('+30 seconds'));
        $this->em->persist($run);
        $this->em->persist(new RecommendationRunLog(
            $run,
            RecommendationRunLog::PHASE_BATCH,
            1,
            1,
            $requestBody,
            $startedAt->modify('+10 seconds'),
        ));

        return $run;
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
        self::assertCount(1, $this->logRowsOfLatestRun());
    }

    public function testStartSpawnsTheDrainerWhenNoWorkerIsAlive(): void
    {
        $this->seedReadyAiSettings($this->user);
        $launcher = new RecordingProcessLauncher();

        $this->starterWith($launcher)->start($this->user);

        self::assertSame([['app:recommendations:drain', '--detach']], $launcher->launches);
    }

    /**
     * A second start() in the same process must NOT fork a second drainer:
     * the first launch is still the one that drains this run, and every extra
     * fork boots a full Symfony kernel only to lose the drain lock and exit
     * (#371 final review, Finding 3). This is the caller-side proof of the
     * spawner's one-launch-per-process guard -- a repeated click, and, through
     * the same code path, a maintenance tick that starts several due runs.
     */
    public function testASecondStartInTheSameProcessDoesNotSpawnAgain(): void
    {
        $this->seedReadyAiSettings($this->user);
        $launcher = new RecordingProcessLauncher();
        $starter = $this->starterWith($launcher);

        $starter->start($this->user);
        $starter->start($this->user);

        self::assertSame([['app:recommendations:drain', '--detach']], $launcher->launches);
    }

    /**
     * The other half of that guard: it is per process, not per run. A later
     * request that finds the run already active is exactly the moment a
     * respawn helps -- the drainer that started it may be long dead -- so a
     * fresh starter, standing in for that fresh request, must still spawn even
     * though it opens no new run.
     */
    public function testStartInALaterRequestSpawnsForAnAlreadyActiveRun(): void
    {
        $this->seedReadyAiSettings($this->user);
        $this->starterWith(new RecordingProcessLauncher())->start($this->user);

        $laterRequest = new RecordingProcessLauncher();
        $this->starterWith($laterRequest)->start($this->user);

        self::assertSame([['app:recommendations:drain', '--detach']], $laterRequest->launches);
    }

    public function testStartDoesNotSpawnNextToAFreshWorkerHeartbeat(): void
    {
        $this->seedReadyAiSettings($this->user);
        $launcher = new RecordingProcessLauncher();
        $this->presence()->mark(RecommendationDriverKind::PersistentWorker);

        $this->starterWith($launcher)->start($this->user);

        self::assertSame([], $launcher->launches);
    }

    public function testResumeSpawnsTheDrainer(): void
    {
        $this->seedReadyAiSettings($this->user);
        $this->failedRunFor($this->user);
        $launcher = new RecordingProcessLauncher();

        $this->starterWith($launcher)->resume($this->user);

        self::assertSame([['app:recommendations:drain', '--detach']], $launcher->launches);
    }

    /**
     * Built by hand so only the launcher is a stub: every other collaborator is
     * the container's real instance, and the spawner's presence read is a real
     * repository query.
     */
    private function starterWith(DetachedProcessLauncherInterface $launcher): RecommendationRunStarter
    {
        /** @var AiProviderConfigurator $configurator */
        $configurator = self::getContainer()->get(AiProviderConfigurator::class);
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        return new RecommendationRunStarter(
            $this->runs(),
            $configurator,
            $this->em,
            $clock,
            $this->runLogs(),
            new RecommendationDrainSpawner($this->presence(), $launcher),
        );
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }

    /**
     * Every run of the account that still holds debug rows, oldest first —
     * the retention window seen from the outside. Read across runs on
     * purpose: what the window drops is invisible from any single run.
     *
     * @return list<int>
     */
    private function runIdsHoldingLogs(): array
    {
        /** @var list<array{runId: int|string}> $rows */
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(l.run) AS runId FROM App\\Entity\\RecommendationRunLog l '
                . 'JOIN l.run r WHERE r.user = :user ORDER BY l.id ASC',
        )->setParameter('user', $this->user)->getArrayResult();

        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['runId'],
            $rows,
        )));
    }

    /**
    /**
     * @return list<array{id: int, runId: int, phase: string, batchNumber: ?int, attempt: int,
     *     verdict: ?string, requestBytes: int, responseBytes: int, wireBytes: int,
     *     createdAt: string, finishedAt: ?string, errorDetail: ?string, finishReason: ?string}>
     */
    private function logRowsOfLatestRun(): array
    {
        $run = $this->runs()->findLatestForUser($this->user);
        self::assertNotNull($run);

        return $this->runLogs()->listForRun($this->user, $run->getId() ?? 0);
    }

    private function runLogs(): RecommendationRunLogRepository
    {
        /** @var RecommendationRunLogRepository $repository */
        $repository = self::getContainer()->get(RecommendationRunLogRepository::class);

        return $repository;
    }

    private function seedReadyAiSettings(
        User $user,
        string $baseUrl = 'https://api.example.test/v1',
        string $model = 'm',
    ): AiProviderSettings {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $sealed = $cipher->seal($userId, 'sk-throwaway1234');
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');

        $settings = new AiProviderSettings($user, null, $baseUrl, $sealed, '1234', $now);
        $this->em->persist($settings);
        $settings->chooseModel($model, $now, 32768);
        $user->setActiveAiProviderSettings($settings);
        $this->em->flush();

        return $settings;
    }

    /** Gives $this->user a ready configuration at the given endpoint, so the
     *  provider-stamping tests (#409) don't have to build the settings row
     *  themselves. */
    private function userWithProvider(string $baseUrl, string $model): User
    {
        $this->seedReadyAiSettings($this->user, $baseUrl, $model);

        return $this->user;
    }

    /** Replaces $user's active configuration with a fresh one at a different
     *  endpoint — the account "changed providers" between two starter calls. */
    private function switchProvider(User $user, string $baseUrl, string $model): void
    {
        $this->seedReadyAiSettings($user, $baseUrl, $model);
    }

    /** A failed run with one batch already ranked, so resume() has a partial
     *  result to continue from and start() has a run to leave as history. */
    private function failedRunFor(User $user): RecommendationRun
    {
        $failed = new RecommendationRun($user, new \DateTimeImmutable('2026-08-07 09:00:00'));
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
