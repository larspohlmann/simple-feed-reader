<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\EntryIngestor;
use App\Service\EntryPruner;
use App\Service\EntrySanitizer;
use App\Service\FeedScheduler;
use App\Service\Fetch\FaviconResolver;
use App\Service\Fetch\FetchResponse;
use App\Service\Maintenance\MaintenanceTick;
use App\Service\OrphanedFeedReclaimer;
use App\Service\Process\DetachedProcessLauncherInterface;
use App\Service\Recommendation\ForYouSweep;
use App\Service\Recommendation\RecommendationDrainSpawner;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\RefreshRunner;
use App\Service\Worker\WorkerPresence;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MaintenanceTickTest extends DbTestCase
{
    public function testRunProducesAReportCarryingBothHalves(): void
    {
        $tick = self::getContainer()->get(MaintenanceTick::class);
        self::assertInstanceOf(MaintenanceTick::class, $tick);

        $report = $tick->run()->toArray();

        // The refresh half always carries a status; the recommendations half
        // always carries the three sweep counts. Exact values are not asserted:
        // the shared test database may hold rows from other classes, so this
        // proves the shape, not a fixed count.
        self::assertArrayHasKey('status', $report['refresh']);
        self::assertIsInt($report['recommendations']['startedRuns']);
        self::assertIsInt($report['recommendations']['advancedRuns']);
        self::assertIsInt($report['recommendations']['activeRuns']);
        self::assertArrayNotHasKey('skipped', $report['recommendations']);
    }

    /**
     * The scenario RefreshReport::isAborted() exists for: a failed flush closes
     * the shared EntityManager (RefreshRunner's own doc), so calling the sweep
     * against it would throw EntityManagerClosed. RefreshRunner and
     * MaintenanceTick are both `final`, so this suite cannot mock either one
     * (no dg/bypass-finals) — instead this reproduces the abort exactly as
     * RefreshRunnerTest does: RefreshRunner takes EntityManagerInterface, an
     * interface, so a stub that throws on flush() forces a genuine `aborted`
     * status deterministically. MaintenanceTick is then built by hand around
     * that runner and the container's real ForYouSweep, proving the guard
     * routes to the skip branch instead of calling sweepOnce(). It cannot
     * reproduce the literal EntityManagerClosed throw — that needs the shared
     * default EntityManager itself to be swappable, and it is not (only the
     * fetcher and a few other collaborators are made public for tests) — so
     * the proof here is behavioural: the recommendations half comes back
     * `skipped` rather than run, which is only possible if the sweep was never
     * called.
     */
    public function testSkipsTheRecommendationSweepWhenRefreshAborts(): void
    {
        $clock = new MockClock('2026-08-10 12:00:00', 'UTC');
        $subscriber = new User('tick-abort-fixture@example.com', $clock->now());
        $this->em->persist($subscriber);

        $feed = new Feed('https://one.example.com/feed');
        $feed->setNextFetchAt($clock->now()->modify('-1 hour'));
        $this->em->persist($feed);
        $this->em->persist(new Subscription($subscriber, $feed, $clock->now()));
        $this->em->flush();

        $fetcher = new StubFeedFetcher($clock);
        $fetcher->willReturn(
            $feed->getUrl(),
            FetchResponse::fetched(
                $feed->getUrl(),
                false,
                /** @lang TEXT */ '<?xml version="1.0"?><rss version="2.0"><channel><title>F</title>'
                    . '<item><title>Post</title><link>https://one.example.com/p</link><guid>g-1</guid></item>'
                    . '</channel></rss>',
                null,
                null,
            ),
        );

        $failingEm = $this->createStub(EntityManagerInterface::class);
        $failingEm->method('flush')->willThrowException(new UniqueConstraintViolationException(
            new class ('duplicate key', '23000', 1062) extends DriverAbstractException {
            },
            null,
        ));

        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->em->getRepository(Feed::class);
        /** @var EntryRepository $entryRepository */
        $entryRepository = $this->em->getRepository(Entry::class);

        $bodyParser = self::getContainer()->get(FeedBodyParser::class);
        self::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        $refreshRunner = new RefreshRunner(
            $feedRepository,
            $failingEm,
            $fetcher,
            $bodyParser,
            new EntryIngestor($this->em, $entryRepository, new EntrySanitizer()),
            new FaviconResolver($fetcher, new NullLogger()),
            new FeedScheduler($clock),
            new EntryPruner($this->em, $clock),
            new OrphanedFeedReclaimer($this->em),
            new LockFactory(new InMemoryStore()),
            $clock,
            new NullLogger(),
        );

        $forYouSweep = self::getContainer()->get(ForYouSweep::class);
        self::assertInstanceOf(ForYouSweep::class, $forYouSweep);

        $launcher = $this->recordingLauncher();
        $tick = new MaintenanceTick($refreshRunner, $forYouSweep, $this->spawnerWith($launcher));

        $report = $tick->run()->toArray();

        self::assertSame('aborted', $report['refresh']['status']);
        self::assertSame(
            [
                'startedRuns' => 0,
                'advancedRuns' => 0,
                'activeRuns' => 0,
                'skipped' => 'refresh aborted: the shared EntityManager is unusable this tick',
            ],
            $report['recommendations'],
        );
        // The aborted path must not spawn either: the shared EntityManager is
        // unusable this tick, so even the spawner's heartbeat read is off-limits.
        self::assertSame([], $launcher->launches);
    }

    public function testTickWithARemainingActiveRunSpawnsTheDrainerAsRespawnNet(): void
    {
        $user = $this->userWithReadyAiSettings('tick-respawn-net@example.test');
        // A candidate entry, so the pending run's snapshot step freezes a real
        // batch plan instead of completing immediately on an empty pool -- the
        // run must still be active for the respawn net to have anything to see.
        $feed = new Feed('https://tick-respawn-net.example.test/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-08-08T00:00:00Z')));
        $this->em->flush();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        (new RecommendationRunFixtures($this->em, $cipher))->entry($feed, 'tick-respawn-net-entry', 30);

        $starter = self::getContainer()->get(RecommendationRunStarter::class);
        self::assertInstanceOf(RecommendationRunStarter::class, $starter);
        $starter->start($user);

        $launcher = $this->recordingLauncher();
        $tick = new MaintenanceTick(
            $this->containerRefreshRunner(),
            $this->containerForYouSweep(),
            $this->spawnerWith($launcher),
        );

        $tick->run();

        // The sweep advanced the run one step (snapshot), it is still active,
        // and no worker heartbeat is fresh -- so the respawn net must fire.
        self::assertSame([['app:recommendations:drain', '--detach']], $launcher->launches);
    }

    public function testTickWithNoActiveRunsDoesNotSpawn(): void
    {
        $launcher = $this->recordingLauncher();
        $tick = new MaintenanceTick(
            $this->containerRefreshRunner(),
            $this->containerForYouSweep(),
            $this->spawnerWith($launcher),
        );

        $tick->run();

        self::assertSame([], $launcher->launches);
    }

    /**
     * @return DetachedProcessLauncherInterface&object{launches: list<list<string>>}
     */
    private function recordingLauncher(): DetachedProcessLauncherInterface
    {
        return new class implements DetachedProcessLauncherInterface {
            /** @var list<list<string>> */
            public array $launches = [];

            public function launch(string $consoleCommandName, string ...$arguments): void
            {
                $this->launches[] = array_values([$consoleCommandName, ...$arguments]);
            }
        };
    }

    private function spawnerWith(DetachedProcessLauncherInterface $launcher): RecommendationDrainSpawner
    {
        $presence = self::getContainer()->get(WorkerPresence::class);
        self::assertInstanceOf(WorkerPresence::class, $presence);

        return new RecommendationDrainSpawner($presence, $launcher);
    }

    private function containerRefreshRunner(): RefreshRunner
    {
        $runner = self::getContainer()->get(RefreshRunner::class);
        self::assertInstanceOf(RefreshRunner::class, $runner);

        return $runner;
    }

    private function containerForYouSweep(): ForYouSweep
    {
        $sweep = self::getContainer()->get(ForYouSweep::class);
        self::assertInstanceOf(ForYouSweep::class, $sweep);

        return $sweep;
    }

    private function userWithReadyAiSettings(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->em, $hasher))->create($email);

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        (new RecommendationRunFixtures($this->em, $cipher))->seedReadyAiSettings($user);

        return $user;
    }
}
