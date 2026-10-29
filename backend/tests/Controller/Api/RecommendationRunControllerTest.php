<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Enum\RecommendationEngineKind;
use App\Enum\RunStatus;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Process\DetachedProcessLauncher\DetachedProcessLauncherInterface;
use App\Service\Recommendation\Profile\ProfileRunStarter;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ProvidesWorkerHeartbeats;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\RecordingProcessLauncher;
use App\Tests\Support\StubChatClient;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class RecommendationRunControllerTest extends ApiTestCase
{
    use ProvidesWorkerHeartbeats;

    /** Must match framework.rate_limiter.ai_recommendation_starts.limit in rate_limiter.yaml. */
    private const int START_BUDGET = 10;

    /** Must match framework.rate_limiter.ai_recommendations.limit in rate_limiter.yaml. */
    private const int TICK_BUDGET = 90;

    protected function setUp(): void
    {
        // The limiter counts in a filesystem pool that outlives the test, so a prior case's spend would trip a 429.
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    private function seedReadyAiSettings(User $user): void
    {
        $this->fixtures()->seedReadyAiSettings($user);
    }

    private function fixtures(): RecommendationRunFixtures
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);

        return new RecommendationRunFixtures($entityManager, $cipher);
    }

    /**
     * A single candidate is enough: the default candidate pool packs it into
     * one batch, which is what the tick sequence below needs.
     */
    private function seedOneCandidateEntry(User $user): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $publishedAt = new \DateTimeImmutable('-1 hour');
        $entry = new Entry(
            $feed,
            'g1',
            'https://example.com/1',
            'Post 1',
            $publishedAt,
            $publishedAt,
        );
        $entry->setPublishedAt($publishedAt);
        $entityManager->persist($entry);
        $entityManager->flush();
    }

    private function stubChatClient(): StubChatClient
    {
        $client = self::getContainer()->get(StubChatClient::class);
        self::assertInstanceOf(StubChatClient::class, $client);

        return $client;
    }

    /** @return array{0: array{HTTP_AUTHORIZATION: string}, 1: User} */
    private function authWithReadyAi(string $email): array
    {
        [$headers, $user] = $this->userAndBearer($email);
        $this->seedReadyAiSettings($user);

        return [$headers, $user];
    }

    /** @param array<string,string> $headers */
    private function startRun(KernelBrowser $client, array $headers): void
    {
        $client->request('POST', '/api/recommendations/runs', server: $headers);
        self::assertResponseIsSuccessful();
    }

    /** A failed run with one batch already ranked, so resume() has something to
     *  continue from at the batch that failed. */
    private function persistFailedRun(User $user): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-08 09:00:00'));
        $run->snapshot(RecommendationEngineKind::Llm, [[1, 2], [3]]);
        $run->recordBatchWinners([['id' => 1, 'score' => 50, 'reason' => 'r']]);
        $run->fail('provider unreachable', new \DateTimeImmutable('2026-08-08 09:05:00'));
        $entityManager->persist($run);
        $entityManager->flush();
    }

    /**
     * Touches the persistent worker's heartbeat through the real repository and the container's own clock, the
     * wiring the poll driver reads: a MockClock stand-in would prove nothing about it.
     */
    private function touchWorkerHeartbeatNow(): void
    {
        $this->touchHeartbeatNow(RecommendationDriverKind::PersistentWorker->heartbeatName());
    }

    private function touchDrainerHeartbeatNow(): void
    {
        $this->touchHeartbeatNow(RecommendationDriverKind::OnDemandDrainer->heartbeatName());
    }

    private function touchHeartbeatNow(string $name): void
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $this->heartbeats()->touch($name, $clock->now());
    }

    public function testTheStatusOfARunWaitingForItsProfileSaysSo(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->userAndBearer('runs-waiting-profile@example.test');
        $this->fixtures()->seedSingleBatchFixture($user);

        $client->request('POST', '/api/recommendations/runs', server: $headers);
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame('pending', $payload['status']);
        self::assertTrue($payload['waitingForProfile']);
    }

    /** A profile run of the account's own schedule is no wait for a run already past its snapshot. */
    public function testARunningRunIsNotWaitingForAProfileRunThatHappensMeanwhile(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->userAndBearer('runs-running-profile@example.test');
        $this->fixtures()->seedSingleBatchFixture($user);
        $this->fixtures()->storeProfile($user, 'a stored profile');
        $client->request('POST', '/api/recommendations/runs', server: $headers);
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        $profileRunStarter = self::getContainer()->get(ProfileRunStarter::class);
        self::assertInstanceOf(ProfileRunStarter::class, $profileRunStarter);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $profileRunStarter->start(
            $entityManager->find(User::class, $user->requireId()) ?? self::fail('The user is gone.'),
            ProfileRunTrigger::Scheduled,
        );

        $client->request('GET', '/api/recommendations/runs/current', server: $headers);

        $payload = $this->payload($client);
        self::assertSame('running', $payload['status']);
        self::assertFalse($payload['waitingForProfile']);
    }

    public function testAnUnauthenticatedTickIsRejected(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/recommendations/runs/tick');

        self::assertResponseStatusCodeSame(401);
    }

    public function testStartingWithoutAiConfiguredIsNotFound(): void
    {
        $client = self::createClient();
        [$headers] = $this->userAndBearer('run-noai@example.test');

        $client->request('POST', '/api/recommendations/runs', server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('ai_not_configured', $this->payload($client)['type']);
    }

    public function testStartingAReadyAccountReportsPending(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->userAndBearer('run-start@example.test');
        $this->seedReadyAiSettings($user);

        $client->request('POST', '/api/recommendations/runs', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertIsInt($payload['elapsedSeconds']);
        self::assertGreaterThanOrEqual(0, $payload['elapsedSeconds']);
        unset($payload['elapsedSeconds']);
        self::assertSame(
            [
                'status' => 'pending',
                'batchesTotal' => null,
                'batchesDone' => 0,
                'error' => null,
                'background' => false,
                'waitingForLock' => false,
                'waitingForProfile' => false,
                'resumable' => false,
                'streamedChars' => 0,
                'firstBatchStarted' => false,
                'etaSeconds' => null,
                'finishedShare' => null,
                'forYou' => ['totalCount' => 0, 'generatedAt' => null, 'newestRunId' => null],
            ],
            $payload,
        );
    }

    public function testResumeWithoutAFailedRunConflicts(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->userAndBearer('run-resume-none@example.test');
        $this->seedReadyAiSettings($user);

        $client->request('POST', '/api/recommendations/runs/resume', server: $headers);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_resumable_recommendation_run', $this->payload($client)['type']);
    }

    public function testTheStatusOfAFailedRunSaysItCanResume(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->userAndBearer('run-status-resumable@example.test');
        $this->seedReadyAiSettings($user);
        $this->persistFailedRun($user);

        $client->request('GET', '/api/recommendations/runs/current', server: $headers);

        self::assertTrue($this->payload($client)['resumable']);
    }

    public function testTheStatusOfARunThatFailedWaitingForItsProfileSaysItCannotResume(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->userAndBearer('run-status-not-resumable@example.test');
        $this->seedReadyAiSettings($user);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $run->fail('Profile generation failed: gone', new \DateTimeImmutable('2026-10-03 09:05:00'));
        $entityManager->persist($run);
        $entityManager->flush();

        $client->request('GET', '/api/recommendations/runs/current', server: $headers);

        $payload = $this->payload($client);
        self::assertSame('failed', $payload['status']);
        self::assertFalse($payload['resumable']);
    }

    public function testResumeContinuesAFailedRun(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->userAndBearer('run-resume@example.test');
        $this->seedReadyAiSettings($user);
        $this->persistFailedRun($user);

        $client->request('POST', '/api/recommendations/runs/resume', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame('running', $payload['status']);
        self::assertSame(1, $payload['batchesDone']);
        self::assertNull($payload['error']);
    }

    public function testTickSequenceRunsThroughToCompletion(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->userAndBearer('run-tick@example.test');
        $this->seedReadyAiSettings($user);
        $this->seedOneCandidateEntry($user);
        $this->fixtures()->storeProfile($user, 'a distilled profile');

        $client->request('POST', '/api/recommendations/runs', server: $headers);
        self::assertResponseIsSuccessful();

        // The snapshot tick freezes the candidate pool into batches without
        // calling the provider.
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame('running', $this->payload($client)['status']);

        $entry = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Entry::class)
            ->findOneBy(['guid.value' => 'g1']);
        self::assertInstanceOf(Entry::class, $entry);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $entry->getId(), 'score' => 90, 'reason' => 'a good read']],
        ], \JSON_THROW_ON_ERROR));

        // The batch tick spends the one queued reply and checkpoints for the consolidation phase.
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame('running', $this->payload($client)['status']);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $entry->getId(), 'score' => 95, 'reason' => 'a good read']],
            'duplicates' => [],
        ], \JSON_THROW_ON_ERROR));

        // The consolidation tick spends the final queued reply and finalizes
        // the run.
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame('completed', $this->payload($client)['status']);

        $client->request('GET', '/api/recommendations/runs/current', server: $headers);
        self::assertResponseIsSuccessful();
        $current = $this->payload($client);
        self::assertSame('completed', $current['status']);
        self::assertArrayHasKey('elapsedSeconds', $current);
        self::assertIsInt($current['elapsedSeconds']);
        self::assertGreaterThanOrEqual(0, $current['elapsedSeconds']);
        $forYou = $current['forYou'];
        self::assertIsArray($forYou);
        self::assertSame(1, $forYou['totalCount']);
        self::assertArrayNotHasKey('itemCount', $forYou);
        self::assertIsString($forYou['generatedAt']);
        // The completed run's own id rides along, so the client can suppress its divider by identity rather than by
        // matching timestamps.
        self::assertIsInt($forYou['newestRunId']);
    }

    public function testCurrentWithoutAnyRunReportsNone(): void
    {
        $client = self::createClient();
        [$headers] = $this->userAndBearer('run-current-none@example.test');

        $client->request('GET', '/api/recommendations/runs/current', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'status' => 'none',
                'batchesTotal' => null,
                'batchesDone' => 0,
                'error' => null,
                'background' => false,
                'waitingForLock' => false,
                'waitingForProfile' => false,
                'resumable' => false,
                'streamedChars' => 0,
                'firstBatchStarted' => false,
                'elapsedSeconds' => null,
                'etaSeconds' => null,
                'finishedShare' => null,
                'forYou' => ['totalCount' => 0, 'generatedAt' => null, 'newestRunId' => null],
            ],
            $this->payload($client),
        );
    }

    /**
     * With a fresh worker heartbeat a tick is a pure status read: the run stays pending and the provider is never
     * called. That branch never attempts the lock, so waitingForLock stays false whether or not the worker holds it.
     */
    public function testTickDefersToAFreshWorkerHeartbeat(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->authWithReadyAi('defer@example.test');
        $this->seedOneCandidateEntry($user);
        $this->startRun($client, $headers);
        $this->touchWorkerHeartbeatNow();

        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseIsSuccessful();
        $report = $this->payload($client);
        self::assertSame('pending', $report['status']);
        self::assertTrue($report['background']);
        self::assertFalse($report['waitingForLock']);
        self::assertSame([], $this->stubChatClient()->calls());
    }

    /**
     * A live on-demand drainer owns execution as the persistent worker does: a tick driving the run would only
     * collide with it on the per-user lock.
     */
    public function testTickDefersToALiveDrainer(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->authWithReadyAi('defer-to-drainer@example.test');
        $this->seedOneCandidateEntry($user);
        $this->startRun($client, $headers);
        $this->touchDrainerHeartbeatNow();

        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseIsSuccessful();
        $report = $this->payload($client);
        self::assertSame('pending', $report['status']);
        self::assertTrue($report['background']);
        self::assertSame([], $this->stubChatClient()->calls());
    }

    /**
     * Without a fresh heartbeat the tick snapshots the run itself and reports it as foreground work. Nothing contends
     * for the lock, so waitingForLock is false: the baseline the stall case below differs from.
     */
    public function testTickAdvancesWhenTheHeartbeatIsStale(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->authWithReadyAi('stale-heartbeat@example.test');
        $this->seedOneCandidateEntry($user);
        $this->fixtures()->storeProfile($user, 'a stored profile');
        $this->startRun($client, $headers);

        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseIsSuccessful();
        $report = $this->payload($client);
        self::assertSame('running', $report['status']);
        self::assertFalse($report['background']);
        self::assertFalse($report['waitingForLock']);
    }

    /**
     * A tick on an active run with no fresh heartbeat spawns a replacement drainer. The run is seeded through the
     * fixtures, not start(), so only this request's kernel termination (RecommendationDrainOnTerminateListener) can
     * launch one.
     */
    public function testATickOnAnActiveRunWithNoFreshHeartbeatSpawnsAReplacementDrainer(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->authWithReadyAi('tick-spawns-drainer@example.test');
        $this->seedOneCandidateEntry($user);
        $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-16T09:00:00Z'));

        $launcher = new RecordingProcessLauncher();
        self::getContainer()->set(DetachedProcessLauncherInterface::class, $launcher);

        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame([['app:recommendations:drain', '--detach']], $launcher->launches);
    }

    /**
     * The heartbeat is a hint and the per-user lock the truth: a held lock reports somebody else's work, never
     * `busy`, which would make the client stop polling a healthy background run.
     */
    public function testATickThatFindsTheLockHeldReportsTheRunAsSomebodyElsesWork(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->authWithReadyAi('locked-tick@example.test');
        $this->seedOneCandidateEntry($user);
        $this->startRun($client, $headers);

        $logSpy = $this->attachALogSpy();
        $lock = $this->holdTheRunLockFor($user);

        try {
            $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        } finally {
            $lock->release();
        }

        self::assertResponseIsSuccessful();
        $report = $this->payload($client);
        self::assertNotSame('busy', $report['status']);
        self::assertSame('pending', $report['status']);
        self::assertTrue($report['background']);
        // No fresh heartbeat either, so the lock and the heartbeat disagree: a holder no driver kind answers for is
        // the stall.
        self::assertTrue($report['waitingForLock']);
        self::assertSame([], $this->stubChatClient()->calls());

        // The stall, and only the stall, reaches dev.log, naming the lock row an operator has to inspect.
        $records = $logSpy->getRecords();
        self::assertCount(1, $records);
        self::assertSame('WARNING', $records[0]->level->getName());
        self::assertSame(
            'Recommendation run lock is held with no driver heartbeat behind it',
            $records[0]->message,
        );
        self::assertSame('ai-recommendations-' . $user->getId(), $records[0]->context['lock']);
    }

    /**
     * A live worker holding the lock is ordinary and frequent: the presence check answers before the lock is
     * attempted, so nothing is logged.
     */
    public function testATickDeferringToALiveWorkerHoldingTheLockLogsNothing(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->authWithReadyAi('healthy-contention@example.test');
        $this->seedOneCandidateEntry($user);
        $this->startRun($client, $headers);
        $this->touchWorkerHeartbeatNow();

        $logSpy = $this->attachALogSpy();
        $lock = $this->holdTheRunLockFor($user);

        try {
            $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        } finally {
            $lock->release();
        }

        self::assertResponseIsSuccessful();
        $report = $this->payload($client);
        self::assertTrue($report['background']);
        self::assertFalse($report['waitingForLock']);
        self::assertSame([], $logSpy->getRecords());
    }

    /**
     * Pushed onto the default logger, not swapped in: earlier requests have resolved it, and the test container
     * refuses to replace an initialised service. WARNING and above is the stall's level, so no info line reads as one.
     */
    private function attachALogSpy(): TestHandler
    {
        $logSpy = new TestHandler(Level::Warning);
        $logger = self::getContainer()->get('monolog.logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($logSpy);

        return $logSpy;
    }

    private function holdTheRunLockFor(User $user): LockInterface
    {
        $lockFactory = self::getContainer()->get(LockFactory::class);
        self::assertInstanceOf(LockFactory::class, $lockFactory);

        $lock = $lockFactory->createLock('ai-recommendations-' . $user->getId());
        self::assertTrue($lock->acquire());

        return $lock;
    }

    public function testCurrentReportsBackgroundWhenTheWorkerIsAlive(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers] = $this->authWithReadyAi('current-background@example.test');
        $this->startRun($client, $headers);
        $this->touchWorkerHeartbeatNow();

        $client->request('GET', '/api/recommendations/runs/current', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($client)['background']);
    }

    public function testCurrentReportsForegroundWhenNoWorkerIsAlive(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers] = $this->authWithReadyAi('current-foreground@example.test');
        $this->startRun($client, $headers);

        $client->request('GET', '/api/recommendations/runs/current', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload($client)['background']);
    }

    /**
     * Every member of the tick action's provider-refusal union, one at a
     * time: dropping any single type from that catch turns its case into an
     * uncaught throw and an opaque 500 instead of the documented 422.
     *
     * @return iterable<string, array{\RuntimeException}>
     */
    public static function providerRefusals(): iterable
    {
        yield 'an address that does not answer' => [new ProviderUnreachableException('down')];
        yield 'a key the provider refuses' => [new CredentialsRejectedException('refused')];
        yield 'a model the provider no longer offers' => [new ModelNotOfferedException('gone')];
    }

    #[DataProvider('providerRefusals')]
    public function testAProviderRefusalDuringATickIsUnprocessable(\RuntimeException $refusal): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->userAndBearer('run-refused-' . $refusal::class . '@example.test');
        $this->seedReadyAiSettings($user);
        $this->seedOneCandidateEntry($user);
        $this->fixtures()->storeProfile($user, 'a stored profile');

        $client->request('POST', '/api/recommendations/runs', server: $headers);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        self::assertResponseIsSuccessful();

        $this->stubChatClient()->queueFailure($refusal);

        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('ai_provider_rejected', $this->payload($client)['type']);
    }

    /**
     * advance() throws AiNotConfiguredException when the provider row disappears under an active run. tick() maps it
     * as start() does, and fails the run the way the worker driver does, so a poll-only install never retries it.
     */
    public function testATickWhoseConfigurationDisappearedIsNotFound(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->userAndBearer('run-tick-noai@example.test');
        $this->seedReadyAiSettings($user);
        $this->seedOneCandidateEntry($user);

        $client->request('POST', '/api/recommendations/runs', server: $headers);
        self::assertResponseIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $settings = $entityManager->getRepository(AiProviderSettings::class)->findOneBy(['user' => $user]);
        self::assertInstanceOf(AiProviderSettings::class, $settings);
        $entityManager->remove($settings);
        $entityManager->flush();

        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('ai_not_configured', $this->payload($client)['type']);

        $entityManager->clear();
        $run = $entityManager->getRepository(RecommendationRun::class)->findOneBy(['user' => $user], ['id' => 'DESC']);
        self::assertInstanceOf(RecommendationRun::class, $run);
        self::assertSame(RunStatus::Failed, $run->getStatus());
        self::assertSame('The AI provider is no longer configured.', $run->getError());
    }

    /** A stored key that no longer decrypts surfaces at the provider tick, once the run is past its snapshot phase. */
    public function testATickWithAnUnreadableStoredKeyIsUnprocessable(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->userAndBearer('run-tick-unreadable@example.test');
        $this->seedReadyAiSettings($user);
        $this->seedOneCandidateEntry($user);
        $this->fixtures()->storeProfile($user, 'a stored profile');

        $client->request('POST', '/api/recommendations/runs', server: $headers);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        self::assertResponseIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement(
            "UPDATE user_ai_settings SET api_key_ciphertext = 'not-a-sealed-key'",
        );
        $entityManager->clear();

        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('ai_key_unreadable', $this->payload($client)['type']);
    }

    /**
     * start() spends the ai_recommendation_starts budget. Limiters autowire by parameter name, and the two routes'
     * budgets differ so that a start() bound to the looser tick limiter fails here.
     */
    public function testAStartBeyondTheWindowsBudgetIsRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->userAndBearer('run-budget@example.test');
        $this->seedReadyAiSettings($user);

        for ($spent = 1; $spent <= self::START_BUDGET; ++$spent) {
            $client->request('POST', '/api/recommendations/runs', server: $headers);
            self::assertResponseIsSuccessful(sprintf('Request %d was inside the budget.', $spent));
        }

        $client->request('POST', '/api/recommendations/runs', server: $headers);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->payload($client)['type']);
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
    }

    /** With no AI configured advance() is a no-op ('none'), so the ticks exercise the tick limiter alone. */
    public function testATickBeyondTheWindowsBudgetIsRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers] = $this->userAndBearer('run-tick-budget@example.test');

        for ($spent = 1; $spent <= self::TICK_BUDGET; ++$spent) {
            $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
            self::assertResponseIsSuccessful(sprintf('Request %d was inside the budget.', $spent));
        }

        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->payload($client)['type']);
    }

    public function testCurrentIsNeverRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers] = $this->userAndBearer('run-current-unlimited@example.test');

        for ($tick = 0; $tick <= self::TICK_BUDGET; ++$tick) {
            $client->request('GET', '/api/recommendations/runs/current', server: $headers);
            self::assertResponseIsSuccessful();
        }
    }

    public function testPurgeWithNoRunsReportsNone(): void
    {
        $client = self::createClient();
        [$headers] = $this->userAndBearer('run-purge-none@example.test');

        $client->request('DELETE', '/api/recommendations/runs', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'status' => 'none',
                'batchesTotal' => null,
                'batchesDone' => 0,
                'error' => null,
                'background' => false,
                'waitingForLock' => false,
                'waitingForProfile' => false,
                'resumable' => false,
                'streamedChars' => 0,
                'firstBatchStarted' => false,
                'elapsedSeconds' => null,
                'etaSeconds' => null,
                'finishedShare' => null,
                'forYou' => ['totalCount' => 0, 'generatedAt' => null, 'newestRunId' => null],
            ],
            $this->payload($client),
        );
    }

    public function testPurgeClearsAFinishedRunsForYouList(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->authWithReadyAi('run-purge-completed@example.test');
        $this->seedOneCandidateEntry($user);
        $this->fixtures()->storeProfile($user, 'a distilled profile');
        $this->startRun($client, $headers);
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        self::assertResponseIsSuccessful();

        $entry = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Entry::class)
            ->findOneBy(['guid.value' => 'g1']);
        self::assertInstanceOf(Entry::class, $entry);

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $entry->getId(), 'score' => 90, 'reason' => 'a good read']],
        ], \JSON_THROW_ON_ERROR));
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers); // batch tick
        self::assertResponseIsSuccessful();

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $entry->getId(), 'score' => 95, 'reason' => 'a good read']],
            'duplicates' => [],
        ], \JSON_THROW_ON_ERROR));
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers); // consolidation tick
        self::assertResponseIsSuccessful();
        self::assertSame('completed', $this->payload($client)['status']);

        $client->request('DELETE', '/api/recommendations/runs', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame('none', $payload['status']);
        $forYou = $payload['forYou'];
        self::assertIsArray($forYou);
        self::assertSame(0, $forYou['totalCount']);
        self::assertNull($forYou['generatedAt']);

        $client->request('GET', '/api/recommendations/runs/current', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame('none', $this->payload($client)['status']);
    }

    public function testPurgeWithAnActiveRunIsRejected(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->authWithReadyAi('run-purge-active@example.test');
        $this->seedOneCandidateEntry($user);
        $this->startRun($client, $headers);

        $client->request('DELETE', '/api/recommendations/runs', server: $headers);

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('recommendation_run_active', $this->payload($client)['type']);
    }

    public function testStopEndsTheActiveRunAndFreesTheAccountToStartAnother(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->authWithReadyAi('run-stop-active@example.test');
        $this->seedOneCandidateEntry($user);
        $this->startRun($client, $headers);

        $client->request('POST', '/api/recommendations/runs/stop', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', $this->payload($client)['status']);

        // The point of the button: a stopped run must not keep the account
        // locked out of starting a fresh one. Purge is the cheapest probe for
        // that, because it is the endpoint that refuses while a run is active.
        $client->request('DELETE', '/api/recommendations/runs', server: $headers);
        self::assertResponseIsSuccessful();
    }

    public function testStopWithNothingRunningIsRejected(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers] = $this->userAndBearer('run-stop-idle@example.test');

        $client->request('POST', '/api/recommendations/runs/stop', server: $headers);

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame('no_active_recommendation_run', $this->payload($client)['type']);
    }
}
