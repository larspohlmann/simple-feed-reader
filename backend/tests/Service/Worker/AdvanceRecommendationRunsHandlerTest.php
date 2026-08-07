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
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\UserFactory;
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

        $settings = $this->em->getRepository(AiProviderSettings::class)->findOneBy(['user' => $user]);
        self::assertNotNull($settings);
        $this->em->remove($settings);
        $this->em->flush();
        $this->em->clear();

        $this->handler()->__invoke(new AdvanceRecommendationRuns());

        $this->em->clear();
        $failed = $this->runs()->findLatestForUser($user);
        self::assertNotNull($failed);
        self::assertSame(RecommendationRun::STATUS_FAILED, $failed->getStatus());
        self::assertSame('The AI provider is no longer configured.', $failed->getError());
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
