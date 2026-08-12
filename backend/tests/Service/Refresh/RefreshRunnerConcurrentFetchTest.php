<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
use App\Service\EntryIngestor;
use App\Service\EntryPruner;
use App\Service\EntrySanitizer;
use App\Service\FeedScheduler;
use App\Service\Fetch\ConcurrentFeedFetcher;
use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\FaviconResolver;
use App\Service\Fetch\FetchResponse;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\ResponseClassifier;
use App\Service\Fetch\UrlGuard;
use App\Service\OrphanedFeedReclaimer;
use App\Service\Parser\Atom03Parser;
use App\Service\Parser\Atom10Parser;
use App\Service\Parser\FeedParser;
use App\Service\Parser\FeedParserFactory;
use App\Service\Parser\Rss1Parser;
use App\Service\Parser\Rss2Parser;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use App\Service\Refresh\ScrapedBodyParser;
use App\Service\Refresh\XmlBodyParser;
use App\Service\Scraper\HtmlItemExtractor;
use App\Tests\DbTestCase;
use App\Tests\Support\StubFeedFetcher;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Every other budget test drives RefreshRunner against StubFeedFetcher's wave
 * simulation, and ConcurrentFeedFetcher's own tests never exercise a budget —
 * so nothing proves BudgetedFeedQueue's lazy ticket generator and the real
 * concurrent engine cooperate correctly once a budget forces a mid-batch stop.
 * This test wires the REAL ConcurrentFeedFetcher onto a MockHttpClient and
 * drives it through RefreshRunner, exactly as production does.
 */
final class RefreshRunnerConcurrentFetchTest extends DbTestCase
{
    private MockClock $clock;
    private StubFeedFetcher $faviconFetcher;
    private LockFactory $lockFactory;
    private User $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        // Favicon resolution keeps using the stub: this test's subject is the
        // feed-fetch budget gate, not favicon plumbing (already covered
        // elsewhere), and the real engine would otherwise need its own
        // SSRF-guarded MockHttpClient wiring for homepage fetches too.
        $this->faviconFetcher = new StubFeedFetcher();
        $this->lockFactory = new LockFactory(new InMemoryStore());
        // dueFeed() subscribes every fixture feed to this user so the #246
        // orphan sweep (wired into every allDue() request) never deletes a
        // feed this test is trying to fetch.
        $this->subscriber = new User('fixture-subscriber@example.com', $this->clock->now());
        $this->em->persist($this->subscriber);
    }

    private function dueFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $feed->setNextFetchAt($this->clock->now()->modify('-1 hour'));
        $this->em->persist($feed);
        $this->em->persist(new Subscription($this->subscriber, $feed, $this->clock->now()));

        $origin = 'https://' . parse_url($url, \PHP_URL_HOST);
        $this->faviconFetcher->willReturn(
            $origin,
            FetchResponse::fetched($origin, false, '<html lang="en"></html>', null, null),
        );

        return $feed;
    }

    private function runner(ConcurrentFeedFetcher $fetcher): RefreshRunner
    {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->em->getRepository(Feed::class);
        /** @var EntryRepository $entryRepository */
        $entryRepository = $this->em->getRepository(Entry::class);
        $extractor = self::getContainer()->get(HtmlItemExtractor::class);
        self::assertInstanceOf(HtmlItemExtractor::class, $extractor);

        $bodyParser = new FeedBodyParser(new ServiceLocator([
            XmlBodyParser::format() => static fn (): XmlBodyParser => new XmlBodyParser(
                new FeedParser(new FeedParserFactory([
                    new Rss2Parser(),
                    new Atom10Parser(),
                    new Atom03Parser(),
                    new Rss1Parser(),
                ])),
            ),
            ScrapedBodyParser::format() => static fn (): ScrapedBodyParser => new ScrapedBodyParser($extractor),
        ]));

        return new RefreshRunner(
            $feedRepository,
            $this->em,
            $fetcher,
            $bodyParser,
            new EntryIngestor($this->em, $entryRepository, new EntrySanitizer()),
            new FaviconResolver($this->faviconFetcher, new NullLogger()),
            new FeedScheduler($this->clock),
            new EntryPruner($this->em, $this->clock),
            new OrphanedFeedReclaimer($this->em),
            $this->lockFactory,
            $this->clock,
            new NullLogger(),
        );
    }

    /**
     * Every URL resolves to the same public address; the SSRF rules themselves
     * are UrlGuard's own responsibility and already have their own tests.
     */
    private function concurrentFetcher(MockHttpClient $httpClient, int $concurrency = 8): ConcurrentFeedFetcher
    {
        $resolver = new class () implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return ['93.184.216.34'];
            }
        };

        return new ConcurrentFeedFetcher(
            $httpClient,
            new UrlGuard($resolver, new IpValidator()),
            new ResponseClassifier($this->clock),
            $concurrency,
            'TestAgent/1.0',
        );
    }

    private function rss(string $title, string $guid): string
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        return /** @lang TEXT */ <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>{$title}</title>
            <item><title>Post</title><link>https://example.com/p</link><guid>{$guid}</guid></item>
            </channel></rss>
            XML;
    }

    /**
     * BudgetedFeedQueue's safety margin is 10 seconds and the first feed is
     * always started unconditionally; every feed after it is gated purely on
     * wall-clock time remaining against the deadline. A 5-second budget is
     * therefore below the margin from the very first check, so exactly one of
     * three due feeds can ever start — deterministic without needing the
     * fetch itself to consume simulated time. What is under test is whether
     * ConcurrentFeedFetcher, driven through FetchQueue's lazy pull, actually
     * stops asking BudgetedFeedQueue's generator for more tickets once the
     * budget says no, and whether RefreshRunner turns that into an accurate
     * report — not the arithmetic of the gate itself, which BudgetedFeedQueue
     * already tests in isolation.
     */
    public function testTheBudgetGateStopsTheRealConcurrentEngineMidBatch(): void
    {
        $first = $this->dueFeed('https://one.example.com/feed');
        $second = $this->dueFeed('https://two.example.com/feed');
        $third = $this->dueFeed('https://three.example.com/feed');
        $this->em->flush();

        $requests = 0;
        $httpClient = new MockHttpClient(
            function () use (&$requests): MockResponse {
                $requests++;

                return new MockResponse($this->rss('Concurrent', 'c-1'), ['http_code' => 200]);
            },
        );

        $fetcher = $this->concurrentFetcher($httpClient);

        $report = $this->runner($fetcher)->run(RefreshRequest::allDue(5));

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
