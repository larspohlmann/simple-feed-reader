<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\AiProviderSettings;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Worker\Handler\AdvanceRecommendationRunsHandler;
use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\WorkerPresence;
use App\Tests\DbTestCase;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\UserFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Drives the handler through the container's real repository, advancer,
 * presence and entity manager, the same "no mocks" stance as
 * RecommendationRunAdvancerTest -- the handler's whole job is coordinating
 * those collaborators, and a mock would only re-encode that coordination.
 */
final class AdvanceRecommendationRunsHandlerTest extends DbTestCase
{
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    public function testFiringTouchesTheHeartbeatEvenWithNoRuns(): void
    {
        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        self::assertTrue($this->presence()->isRecommendationWorkerAlive());
    }

    public function testDrivesARunToCompletionAcrossFirings(): void
    {
        $user = $this->user('single-batch@example.test');
        $this->seedSingleBatchFixture($user);
        $this->starter()->start($user);

        // Snapshot firing: moves the run from pending into running with its
        // single batch frozen, and makes no provider call yet.
        $this->handler()->__invoke(new AdvanceRecommendationRuns());
        $run = $this->activeRun($user);
        self::assertSame(RecommendationRun::STATUS_RUNNING, $run->getStatus());
        $batch = $run->getCandidateBatches()[0] ?? [];
        self::assertNotSame([], $batch);

        $this->requeueCleanReplyFor($batch);

        // Batch firing: the single batch finalizes the run directly, with no
        // merge call needed.
        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $persisted = $this->runs()->findLatestForUser($user);
        self::assertNotNull($persisted);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $persisted->getStatus());
        self::assertNotCount(0, $this->recommendationItems($persisted));
    }

    /**
     * The load-bearing case: one user's dead provider must not stop the
     * sweep from ticking a second user's run in the very same firing.
     */
    public function testProviderFailureIsLoggedAndDoesNotThrow(): void
    {
        $strugglingUser = $this->user('struggling@example.test');
        $this->seedSingleBatchFixture($strugglingUser);
        $this->startAndSnapshot($strugglingUser);

        $healthyUser = $this->user('healthy@example.test');
        $this->seedSingleBatchFixture($healthyUser);
        $healthyRun = $this->startAndSnapshot($healthyUser);

        // Runs are processed oldest-first, so the failure the struggling
        // user's run queues is consumed before the healthy user's reply.
        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('down'));
        $this->requeueCleanReplyFor($healthyRun->getCandidateBatches()[0]);

        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $stillActive = $this->runs()->findActiveForUser($strugglingUser);
        self::assertNotNull($stillActive);
        self::assertSame(RecommendationRun::STATUS_RUNNING, $stillActive->getStatus());

        $advanced = $this->runs()->findLatestForUser($healthyUser);
        self::assertNotNull($advanced);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $advanced->getStatus());
        self::assertNotCount(0, $this->recommendationItems($advanced));
    }

    public function testUnconfiguredUsersRunIsFailedNotSweptForever(): void
    {
        $user = $this->user('unconfigured@example.test');
        $this->seedSingleBatchFixture($user);
        $this->startAndSnapshot($user);
        $this->deleteAiSettingsFor($user);

        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $failed = $this->runs()->findLatestForUser($user);
        self::assertNotNull($failed);
        self::assertSame(RecommendationRun::STATUS_FAILED, $failed->getStatus());
        self::assertSame('The AI provider is no longer configured.', $failed->getError());
    }

    /**
     * Fix round 1 (#311 review): a run that never reached its first snapshot
     * is still PENDING, not RUNNING, when its AI settings row disappears
     * (DELETE /api/me/ai has no "is there an active run" guard). Unlike
     * every test above, this one deliberately skips startAndSnapshot() so
     * the run stays PENDING going into the firing that removes the row.
     */
    public function testPendingRunLosingConfigurationBeforeItsFirstSnapshotIsFailed(): void
    {
        $user = $this->user('never-snapshotted@example.test');
        $this->seedSingleBatchFixture($user);
        $this->starter()->start($user);
        self::assertSame(RecommendationRun::STATUS_PENDING, $this->activeRun($user)->getStatus());

        $this->deleteAiSettingsFor($user);

        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $failed = $this->runs()->findLatestForUser($user);
        self::assertNotNull($failed);
        self::assertSame(RecommendationRun::STATUS_FAILED, $failed->getStatus());
        self::assertSame('The AI provider is no longer configured.', $failed->getError());
    }

    /**
     * Fix round 1 (#311 review): the same PENDING-loses-its-settings race as
     * above, but with a second, healthy user's run sorted right after it.
     * Before the fix, the first run's LogicException (from fail() guarding
     * RUNNING) escaped __invoke() entirely and the second user's run was
     * never even attempted in this firing.
     */
    public function testFairnessWhenAPendingRunFailsBeforeItsFirstSnapshot(): void
    {
        $strugglingUser = $this->user('never-snapshotted-struggling@example.test');
        $this->seedSingleBatchFixture($strugglingUser);
        $this->starter()->start($strugglingUser);
        self::assertSame(RecommendationRun::STATUS_PENDING, $this->activeRun($strugglingUser)->getStatus());
        $this->deleteAiSettingsFor($strugglingUser);

        $healthyUser = $this->user('healthy-after-pending-failure@example.test');
        $this->seedSingleBatchFixture($healthyUser);
        $healthyRun = $this->startAndSnapshot($healthyUser);
        $this->requeueCleanReplyFor($healthyRun->getCandidateBatches()[0]);

        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $failed = $this->runs()->findLatestForUser($strugglingUser);
        self::assertNotNull($failed);
        self::assertSame(RecommendationRun::STATUS_FAILED, $failed->getStatus());

        $advanced = $this->runs()->findLatestForUser($healthyUser);
        self::assertNotNull($advanced);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $advanced->getStatus());
        self::assertNotCount(0, $this->recommendationItems($advanced));
    }

    /**
     * Fix round 2 (#311 review): the earlier fix still flushed the
     * fail()-recording write INSIDE the catch that decided to record it, so
     * a flush() failure there (lock timeout, dropped connection) threw from
     * within a catch block -- which PHP never routes to a sibling catch --
     * and escaped exactly like the round-1 LogicException did. This
     * reproduces that with a decorator that makes only the FIRST flush()
     * throw, without ever invoking the real EntityManager's UnitOfWork
     * (see FlushFailingEntityManager), so the second, healthy user's run
     * genuinely advances through the real, un-poisoned EntityManager in the
     * very same firing -- the positive assertion, not just "no throw".
     */
    public function testFlushFailureRecordingOneRunsFailureDoesNotStarveTheNext(): void
    {
        $strugglingUser = $this->user('flush-failure-struggling@example.test');
        $this->seedSingleBatchFixture($strugglingUser);
        $this->starter()->start($strugglingUser);
        self::assertSame(RecommendationRun::STATUS_PENDING, $this->activeRun($strugglingUser)->getStatus());
        $this->deleteAiSettingsFor($strugglingUser);

        $healthyUser = $this->user('flush-failure-healthy@example.test');
        $this->seedSingleBatchFixture($healthyUser);
        $healthyRun = $this->startAndSnapshot($healthyUser);
        $this->requeueCleanReplyFor($healthyRun->getCandidateBatches()[0]);

        $this->handlerWithFlushFailingEntityManager()->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        // fail() mutated the struggling run's in-memory object before its own
        // flush() threw, and that object stayed managed in the *same* shared
        // EntityManager the healthy run's advance() goes on to flush
        // successfully -- Doctrine computes changesets for every managed
        // entity at flush time, not just the one the caller had in mind, so
        // the FAILED write actually reaches the database anyway, carried by
        // the next successful flush in this firing. The one thing this test
        // exists to prove is the part that is NOT incidental: the failing
        // flush() itself never aborted the loop, so the healthy run's flush
        // still happened at all in the same firing.
        $struggling = $this->runs()->findLatestForUser($strugglingUser);
        self::assertNotNull($struggling);
        self::assertSame(RecommendationRun::STATUS_FAILED, $struggling->getStatus());

        $advanced = $this->runs()->findLatestForUser($healthyUser);
        self::assertNotNull($advanced);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $advanced->getStatus());
        self::assertNotCount(0, $this->recommendationItems($advanced));
    }

    /**
     * Built by hand rather than fetched from the container: only the
     * handler's OWN view of the EntityManager is replaced with one whose
     * first flush() throws. The repository and advancer arguments are still
     * the container's real, shared instances, so the healthy user's run
     * advances through the real EntityManager exactly as it would in
     * production -- only the struggling run's own failure-recording flush is
     * faked.
     */
    private function handlerWithFlushFailingEntityManager(): AdvanceRecommendationRunsHandler
    {
        return new AdvanceRecommendationRunsHandler(
            $this->runs(),
            $this->advancer(),
            $this->presence(),
            new MockClock('2026-08-08T00:00:00Z'),
            new FlushFailingEntityManager($this->em),
            new NullLogger(),
        );
    }

    private function deleteAiSettingsFor(User $user): void
    {
        $settings = $this->em->getRepository(AiProviderSettings::class)->findOneBy(['user' => $user]);
        self::assertNotNull($settings);
        $this->em->remove($settings);
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * Starts a run and drives one direct advance() call so its single batch
     * is frozen and it is RUNNING -- the same "get to a batch-ready run
     * first" shape RecommendationRunAdvancerTest's own startAndSnapshot()
     * helper uses.
     */
    private function startAndSnapshot(User $user): RecommendationRun
    {
        $this->starter()->start($user);
        $this->advancer()->advance($user);

        return $this->activeRun($user);
    }

    /**
     * @param list<int> $batchIds
     */
    private function requeueCleanReplyFor(array $batchIds): void
    {
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => array_map(
                static fn (int $id): array => ['id' => $id, 'reason' => 'irrelevant'],
                $batchIds,
            ),
        ], \JSON_THROW_ON_ERROR));
    }

    private function seedSingleBatchFixture(User $user): void
    {
        $this->fixtures->seedReadyAiSettings($user);

        $feed = new Feed('https://example.com/' . $user->getEmail() . '/feed.xml');
        $feed->setTitle('Example');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();

        for ($i = 0; $i < 5; $i++) {
            $guid = $user->getEmail() . '-entry-' . $i;
            $this->fixtures->entry($feed, $guid, sprintf('2026-07-%02dT00:00:00Z', 10 + $i));
        }
    }

    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function activeRun(User $user): RecommendationRun
    {
        $run = $this->runs()->findActiveForUser($user);
        self::assertNotNull($run);

        return $run;
    }

    /**
     * @return list<RecommendationItem>
     */
    private function recommendationItems(RecommendationRun $run): array
    {
        /** @var list<RecommendationItem> $items */
        $items = $this->em->getRepository(RecommendationItem::class)->findBy(['run' => $run]);

        return $items;
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

    private function advancer(): RecommendationRunAdvancer
    {
        /** @var RecommendationRunAdvancer $advancer */
        $advancer = self::getContainer()->get(RecommendationRunAdvancer::class);

        return $advancer;
    }

    private function stubChatClient(): StubChatClient
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);

        return $client;
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }

    private function handler(): AdvanceRecommendationRunsHandler
    {
        /** @var AdvanceRecommendationRunsHandler $handler */
        $handler = self::getContainer()->get(AdvanceRecommendationRunsHandler::class);

        return $handler;
    }
}
