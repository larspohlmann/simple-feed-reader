<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
use App\Repository\OrphanedFeedRepository;
use App\Repository\PendingImageVerificationRepository;
use App\Repository\PreferencesRepository;
use App\Repository\RetentionRepository;
use App\Repository\RowIds;
use App\Service\Category\CategoryNormalizer;
use App\Service\Clock\NaiveUtcClock;
use App\Service\FeedScheduler;
use App\Service\Fetch\FaviconResolver;
use App\Service\Fetch\FetchResponse;
use App\Service\Fetch\HostThrottle;
use App\Service\Image\ImageVerificationSweep;
use App\Service\Image\ImageVerifier;
use App\Service\Ingest\EntryCategoryWriter;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\Platform\PlatformEntryRules;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiSpoolReport;
use App\Service\Logging\Loki\LokiSpoolShipper;
use App\Service\Mail\Digest\DigestComposer;
use App\Service\Mail\Digest\DigestMailerInterface;
use App\Service\Mail\Digest\DigestSchedule;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Mail\MailCapability;
use App\Service\Maintenance\MaintenanceSweeps;
use App\Service\Maintenance\MaintenanceTick;
use App\Service\OrphanedFeedReclaimer;
use App\Service\Recommendation\ForYouSweep;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\RefreshRunner;
use App\Service\Retention\EntryPruner;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Search\EntryIndexer;
use App\Service\Url\UrlNormalizer;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\InMemoryMailFailureRecorder;
use App\Tests\Support\StubFaviconFetcher;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\RecordingContentChangeMarker;
use App\Tests\Support\MembershipSweepFactory;
use App\Tests\Support\RecordingSavedSearchMatcher;
use App\Tests\Support\StubLokiEndpoint;
use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class MaintenanceTickTest extends DbTestCase
{
    public function testRunProducesAReportCarryingBothHalves(): void
    {
        $tick = self::getContainer()->get(MaintenanceTick::class);
        self::assertInstanceOf(MaintenanceTick::class, $tick);

        $report = $tick->run();

        // The shared test database may hold other classes' rows: this proves the sweeps ran, not their counts.
        self::assertFalse($report->refresh->isAborted());
        self::assertFalse($report->sweeps->skipped);
    }

    /**
     * An aborted refresh closed the shared EntityManager, so the sweeps must not run; the throwing
     * PreferencesRepository stub fails this test if SendDueDigests::run() is ever reached on that path.
     */
    public function testSkipsTheRecommendationSweepWhenRefreshAborts(): void
    {
        $clock = new MockClock('2026-08-10 12:00:00', 'UTC');
        $subscriber = new User('tick-abort-fixture@example.com', $clock->now());
        $this->em->persist($subscriber);

        $feed = new Feed('https://one.example.com/feed');
        $feed->scheduleNextFetchAt($clock->now()->modify('-1 hour'));
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

        $indexer = new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger());
        $refreshRunner = new RefreshRunner(
            $feedRepository,
            $failingEm,
            $fetcher,
            $bodyParser,
            new EntryIngestor(
                $this->em,
                $entryRepository,
                new EntrySanitizer(),
                new UrlNormalizer(),
                new EntryCategoryWriter(
                    $this->em,
                    $this->em->getRepository(Category::class),
                    new CategoryNormalizer(),
                ),
                new NaiveUtcClock($clock),
                new PlatformEntryRules([]),
            ),
            new FaviconResolver($fetcher, new NullLogger()),
            new FeedScheduler($clock, new HostThrottle(new ArrayAdapter(clock: $clock), $clock)),
            new EntryPruner(new RetentionRepository($this->em, new RowIds($this->em)), $clock, $indexer),
            new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->em)),
            $indexer,
            new LockFactory(new InMemoryStore()),
            $clock,
            new NullLogger(),
            new RecordingContentChangeMarker(),
        );

        $forYouSweep = self::getContainer()->get(ForYouSweep::class);
        self::assertInstanceOf(ForYouSweep::class, $forYouSweep);

        $throwingPreferences = $this->createStub(PreferencesRepository::class);
        $throwingPreferences->method('findWithDigestEnabled')->willThrowException(
            new \LogicException('SendDueDigests::run() must not be called when refresh aborted'),
        );

        $digestSchedule = self::getContainer()->get(DigestSchedule::class);
        self::assertInstanceOf(DigestSchedule::class, $digestSchedule);
        $digestComposer = self::getContainer()->get(DigestComposer::class);
        self::assertInstanceOf(DigestComposer::class, $digestComposer);
        $digestMailer = self::getContainer()->get(DigestMailerInterface::class);
        self::assertInstanceOf(DigestMailerInterface::class, $digestMailer);
        $mailCapability = self::getContainer()->get(MailCapability::class);
        self::assertInstanceOf(MailCapability::class, $mailCapability);

        $sendDueDigests = new SendDueDigests(
            $throwingPreferences,
            $digestSchedule,
            $digestComposer,
            $digestMailer,
            $mailCapability,
            $clock,
            $this->em,
            new NullLogger(),
            new InMemoryMailFailureRecorder(),
        );

        $spoolDirectory = sys_get_temp_dir() . '/loki-tick-' . bin2hex(random_bytes(4));
        $lokiClient = new LokiClient(new MockHttpClient(), new StubLokiEndpoint());
        $logSpoolShipper = new LokiSpoolShipper($lokiClient, $spoolDirectory);

        $pendingImageVerificationRepository = self::getContainer()->get(PendingImageVerificationRepository::class);
        self::assertInstanceOf(PendingImageVerificationRepository::class, $pendingImageVerificationRepository);
        $imageVerificationSweep = new ImageVerificationSweep(
            $pendingImageVerificationRepository,
            new ImageVerifier(new StubFaviconFetcher(), new NaiveUtcClock($clock)),
            $this->em,
        );

        $membershipSweep = MembershipSweepFactory::fromContainer(
            self::getContainer(),
            $this->em,
            new RecordingSavedSearchMatcher(),
            $clock,
        );

        $tick = new MaintenanceTick(
            $refreshRunner,
            $forYouSweep,
            $sendDueDigests,
            $imageVerificationSweep,
            $membershipSweep,
            $logSpoolShipper,
            $clock,
        );

        $report = $tick->run();

        self::assertTrue($report->refresh->isAborted());
        self::assertEquals(MaintenanceSweeps::skippedAfterAbortedRefresh(), $report->sweeps);
        self::assertEquals(new LokiSpoolReport(0, 0), $report->logShipping);
    }
}
