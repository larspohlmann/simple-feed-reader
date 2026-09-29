<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\RefreshRunner;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedStatus;
use App\Service\Fetch\BatchFeedFetcher\ConcurrentFeedFetcher;
use App\Service\Fetch\DnsResolver\DnsResolverInterface;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Fetch\ResponseClassifier;
use App\Service\Fetch\UrlGuard;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Tests\DbTestCase;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\NoEgressProxy;
use App\Tests\Support\RefreshRunners;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Drives the REAL ConcurrentFeedFetcher through RefreshRunner: no other budget
 * test exercises BudgetedFeedQueue's lazy ticket generator against the real
 * concurrent engine once a budget forces a mid-batch stop.
 */
final class RefreshRunnerConcurrentFetchTest extends DbTestCase
{
    use NoEgressProxy;

    private MockClock $clock;
    private StubFeedFetcher $faviconFetcher;
    private User $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        // Favicons keep the stub: the subject is the feed-fetch budget gate, and the real engine would need its own
        // SSRF-guarded MockHttpClient for the homepage fetches.
        $this->faviconFetcher = new StubFeedFetcher();
        // dueFeed() subscribes every fixture feed to this user so the orphan sweep never deletes it.
        $this->subscriber = new User('fixture-subscriber@example.com', $this->clock->now());
        $this->entityManager->persist($this->subscriber);
    }

    private function dueFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $feed->scheduleNextFetchAt($this->clock->now()->modify('-1 hour'));
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($this->subscriber, $feed, $this->clock->now()));

        $origin = 'https://' . parse_url($url, \PHP_URL_HOST);
        $this->faviconFetcher->willReturn(
            $origin,
            FetchResponseModel::fetched($origin, false, '<html lang="en"></html>', null, null),
        );

        return $feed;
    }

    private function runner(ConcurrentFeedFetcher $fetcher): RefreshRunner
    {
        return RefreshRunners::fromContainer(self::getContainer(), $this->entityManager, $this->clock)
            ->build($fetcher, $this->faviconFetcher);
    }

    /**
     * Every URL resolves to the same public address; the SSRF rules themselves
     * are UrlGuard's own responsibility and already have their own tests.
     */
    private function concurrentFetcher(
        MockHttpClient $httpClient,
        int $concurrency = 8,
        int $hostConcurrency = 100,
    ): ConcurrentFeedFetcher {
        $resolver = new class () implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };

        $urlGuard = new UrlGuard($resolver, new IpValidator());

        return new ConcurrentFeedFetcher(
            $httpClient,
            $urlGuard,
            new ResponseClassifier($this->clock),
            $concurrency,
            $hostConcurrency,
            'TestAgent/1.0',
            $this->noEgressProxy(),
            FetchWiring::retryPolicy($urlGuard),
        );
    }

    private function rss(string $title, string $guid): string
    {
        return /** @lang TEXT */ <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>{$title}</title>
            <item><title>Post</title><link>https://example.com/p</link><guid>{$guid}</guid></item>
            </channel></rss>
            XML;
    }

    /**
     * A 304 answering a request that carried no validator confirms nothing, so the engine turns it into an empty
     * fetch, and the empty body fails the parse like any other unreadable document.
     */
    public function testANotModifiedToAnUnconditionalRequestIsRecordedAsAFailure(): void
    {
        $feed = $this->dueFeed('https://one.example.com/feed');
        $this->entityManager->flush();

        $fetcher = $this->concurrentFetcher(new MockHttpClient(new MockResponse('', ['http_code' => 304])));
        $report = $this->runner($fetcher)->run(RefreshRequestModel::allDue(300));

        self::assertSame(0, $report->notModified);
        self::assertSame(1, $report->failed);
        self::assertSame(FeedStatus::Erroring, $feed->getStatus());
    }

    public function testANotModifiedToAConditionalRequestKeepsTheFeedHealthy(): void
    {
        $feed = $this->dueFeed('https://one.example.com/feed');
        $feed->recordCacheValidators('"v1"', null);
        $this->entityManager->flush();

        $fetcher = $this->concurrentFetcher(new MockHttpClient(new MockResponse('', ['http_code' => 304])));
        $report = $this->runner($fetcher)->run(RefreshRequestModel::allDue(300));

        self::assertSame(1, $report->notModified);
        self::assertSame(0, $report->failed);
        self::assertSame(FeedStatus::Active, $feed->getStatus());
    }

    /**
     * BudgetedFeedQueue always starts the first feed and gates each later one on a 10-second margin, so a 5-second
     * budget lets one of three start. Under test: the real engine stops pulling tickets once the budget says no, and
     * the runner reports it; the gate's arithmetic is BudgetedFeedQueueTest's.
     */
    public function testTheBudgetGateStopsTheRealConcurrentEngineMidBatch(): void
    {
        $first = $this->dueFeed('https://one.example.com/feed');
        $second = $this->dueFeed('https://two.example.com/feed');
        $third = $this->dueFeed('https://three.example.com/feed');
        $this->entityManager->flush();

        $requests = 0;
        $httpClient = new MockHttpClient(
            function () use (&$requests): MockResponse {
                $requests++;

                return new MockResponse($this->rss('Concurrent', 'c-1'), ['http_code' => 200]);
            },
        );

        $fetcher = $this->concurrentFetcher($httpClient);

        $report = $this->runner($fetcher)->run(RefreshRequestModel::allDue(5));

        self::assertSame('partial', $report->status);
        self::assertSame(3, $report->total);
        self::assertSame(2, $report->skippedForBudget);
        // The seam under test: skipped tickets were never yielded, so
        // ConcurrentFeedFetcher never pulled them off BudgetedFeedQueue's
        // generator and never asked the HTTP client for them.
        self::assertSame(1, $requests);
        self::assertSame(1, $report->fetched + $report->notModified + $report->failed);
        self::assertSame(
            $report->total,
            $report->skippedForBudget + $report->fetched + $report->notModified + $report->failed,
        );
        self::assertGreaterThan(0, $report->remaining);

        // The feed actually started is the earliest-due one (stable id order);
        // the two the budget deferred are still due for the next run and were
        // never touched.
        self::assertNotNull($first->getLastFetchedAt());
        self::assertNull($second->getLastFetchedAt());
        self::assertNull($third->getLastFetchedAt());
    }
}
