<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\PendingImageVerificationRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Image\ImageVerificationSweep;
use App\Service\Image\ImageVerifier;
use App\Service\Logging\Loki\LokiClient;
use App\Service\Logging\Loki\LokiSpoolShipper;
use App\Service\Logging\Loki\Model\LokiSpoolReportModel;
use App\Service\Mail\Digest\DigestComposer;
use App\Service\Mail\Digest\DigestMailer\DigestMailerInterface;
use App\Service\Mail\Digest\DigestRecipients\DigestRecipientsInterface;
use App\Service\Mail\Digest\DigestSchedule;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Mail\MailCapability;
use App\Service\Maintenance\MaintenanceTick;
use App\Service\Maintenance\Model\MaintenanceSweepsModel;
use App\Service\Recommendation\Run\ForYouSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\DuplicateKeyViolation;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\InMemoryMailFailureRecorder;
use App\Tests\Support\MembershipSweepFactory;
use App\Tests\Support\RecordingSavedSearchMatcher;
use App\Tests\Support\RefreshRunners;
use App\Tests\Support\StubFaviconFetcher;
use App\Tests\Support\StubFeedFetcher;
use App\Tests\Support\StubLokiEndpoint;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

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
     * DigestRecipientsInterface stub fails this test if SendDueDigests::run() is ever reached on that path.
     */
    public function testSkipsTheRecommendationSweepWhenRefreshAborts(): void
    {
        $clock = new MockClock('2026-08-10 12:00:00', 'UTC');
        $subscriber = new User('tick-abort-fixture@example.com', $clock->now());
        $this->entityManager->persist($subscriber);

        $feed = new Feed('https://one.example.com/feed');
        $feed->scheduleNextFetchAt($clock->now()->modify('-1 hour'));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($subscriber, $feed, $clock->now()));
        $this->entityManager->flush();

        $fetcher = new StubFeedFetcher($clock);
        $fetcher->willReturn(
            $feed->getUrl(),
            FetchResponseModel::fetched(
                $feed->getUrl(),
                false,
                /** @lang TEXT */ '<?xml version="1.0"?><rss version="2.0"><channel><title>F</title>'
                    . '<item><title>Post</title><link>https://one.example.com/p</link><guid>g-1</guid></item>'
                    . '</channel></rss>',
                null,
                null,
            ),
        );

        $failingEntityManager = new FlushFailingEntityManager(
            $this->entityManager,
            thrown: DuplicateKeyViolation::exception(),
        );

        $refreshRunner = RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $clock)
            ->flushingThrough($failingEntityManager)
            ->build($fetcher, $fetcher);

        $forYouSweep = self::getContainer()->get(ForYouSweep::class);
        self::assertInstanceOf(ForYouSweep::class, $forYouSweep);

        $throwingPreferences = $this->createStub(DigestRecipientsInterface::class);
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
            $this->entityManager,
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
            $this->entityManager,
        );

        $membershipSweep = MembershipSweepFactory::fromContainer(
            self::getContainer(),
            $this->entityManager,
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
        self::assertEquals(MaintenanceSweepsModel::skippedAfterAbortedRefresh(), $report->sweeps);
        self::assertEquals(new LokiSpoolReportModel(0, 0), $report->logShipping);
    }
}
