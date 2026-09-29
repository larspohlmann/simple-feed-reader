<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Enum\SourceFormat;
use App\Repository\OrphanedFeedRepository;
use App\Service\Discovery\Exception\ScrapingDisabledException;
use App\Service\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Discovery\Model\DiscoveredFeedModel;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Discovery\Model\FeedDiscoveryResultModel;
use App\Service\Discovery\Model\ScrapeFallback;
use App\Service\Discovery\ScrapeFallbackPolicy;
use App\Service\Feed\Factory\FeedFactory;
use App\Service\Feed\OrphanedFeedReclaimer;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Search\EntryIndexer;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Subscription\FirstFetchRecorder;
use App\Service\Subscription\SubscriptionCreator;
use App\Service\Subscription\SubscriptionLimitResolver;
use App\Service\Subscription\SubscriptionService;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\EntryIngestors;
use App\Tests\Support\FeedSchedulers;
use App\Tests\Support\UserFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriptionServiceTest extends DbTestCase
{
    private function factory(): UserFactory
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($this->entityManager, $hasher);
    }

    /** A FeedDiscovery test double returning a fixed result. */
    private function discoveryReturning(FeedDiscoveryResultModel $result): FeedDiscoveryInterface
    {
        return new class ($result) implements FeedDiscoveryInterface {
            public function __construct(private readonly FeedDiscoveryResultModel $result)
            {
            }

            public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResultModel
            {
                return $this->result;
            }
        };
    }

    /** The document a real discovery would carry back, with one entry to ingest. */
    private function discovered(string $url, string $title = 'Discovered'): FeedDiscoveryResultModel
    {
        $entry = new ParsedEntryModel(
            guid: $url . '#1',
            title: 'First post',
            url: $url . '/1',
            author: null,
            publishedAt: new \DateTimeImmutable('2026-05-31T12:00:00Z'),
            contentHtml: '<p>Hello.</p>',
            summary: null,
        );

        return FeedDiscoveryResultModel::directFeed(new DiscoveredFeedModel(
            $url,
            new ParsedFeedModel($title, null, null, null, [$entry]),
        ));
    }

    private function service(FeedDiscoveryInterface $discovery): SubscriptionService
    {
        $clock = new MockClock('2026-06-01T00:00:00Z');

        return new SubscriptionService(
            $discovery,
            new SubscriptionCreator(
                $this->entityManager->getRepository(Subscription::class),
                $this->entityManager->getRepository(Feed::class),
                $this->entityManager->getRepository(SubscriptionTag::class),
                $this->entityManager,
                $clock,
                new SubscriptionLimitResolver(),
                new FeedFactory(),
            ),
            new ScrapeFallbackPolicy(),
            new FirstFetchRecorder(
                EntryIngestors::build($this->entityManager, $clock),
                FeedSchedulers::build($clock),
                $this->entityManager,
                $clock,
                new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            ),
            new OrphanedFeedReclaimer(new OrphanedFeedRepository($this->entityManager)),
            $this->entityManager,
        );
    }

    public function testDirectFeedCreatesFeedAndSubscription(): void
    {
        $user = $this->factory()->create('sub@example.com');

        $service = $this->service(
            $this->discoveryReturning($this->discovered('https://example.com/feed.xml')),
        );

        $outcome = $service->subscribe($user, 'https://example.com/feed');

        self::assertNotNull($outcome->subscription);
        self::assertSame('https://example.com/feed.xml', $outcome->subscription->getFeed()->getUrl());
        self::assertSame([], $outcome->candidates);
    }

    /**
     * The #290 promise: discovery already read the document, so the feed
     * arrives with its entries and on the normal schedule. Nothing fetches the
     * URL a second time, which is what a rationing site answers with 429.
     */
    public function testANewSubscriptionArrivesWithTheEntriesDiscoveryAlreadyRead(): void
    {
        $user = $this->factory()->create('seeded@example.com');

        $service = $this->service($this->discoveryReturning($this->discovered(
            'https://example.com/feed.xml',
            'Discovered Feed',
        )));

        $feed = $service->subscribe($user, 'https://example.com/feed')->subscription?->getFeed();

        self::assertNotNull($feed);
        self::assertSame('Discovered Feed', $feed->getTitle());
        $entries = $this->entityManager->getRepository(Entry::class)->findBy(['feed' => $feed]);
        self::assertCount(1, $entries);
        // The recorder's clock-now is the entry's createdAt (the run-start for
        // the list's run-first sort — a first-subscribe fetch is a one-feed run).
        self::assertSame(
            (new \DateTimeImmutable('2026-06-01T00:00:00Z'))->getTimestamp(),
            $entries[0]->getCreatedAt()->getTimestamp(),
        );
        self::assertNotNull($feed->getLastFetchedAt());
        self::assertNotNull($feed->getNextFetchAt());
    }

    /**
     * A feed somebody else already subscribed to has a schedule and a history
     * of its own; this user's subscribe is no reason to rewrite either.
     */
    public function testAnAlreadyFetchedFeedKeepsItsSchedule(): void
    {
        $fetchedAt = new \DateTimeImmutable('2026-05-30T09:00:00Z');
        $shared = new Feed('https://example.com/feed.xml');
        $shared->recordSuccessfulFetch($fetchedAt, 60);
        $this->entityManager->persist($shared);
        $this->entityManager->flush();

        $service = $this->service($this->discoveryReturning($this->discovered('https://example.com/feed.xml')));
        $service->subscribe($this->factory()->create('second@example.com'), 'https://example.com/feed');

        self::assertSame($fetchedAt->format('c'), $shared->getLastFetchedAt()?->format('c'));
        self::assertSame([], $this->entityManager->getRepository(Entry::class)->findBy(['feed' => $shared]));
    }

    public function testSecondSubscriptionToSameFeedIsRejected(): void
    {
        $user = $this->factory()->create('dupe@example.com');

        $service = $this->service(
            $this->discoveryReturning($this->discovered('https://example.com/feed.xml')),
        );

        $service->subscribe($user, 'https://example.com/feed');

        $this->expectException(AlreadySubscribedException::class);
        $service->subscribe($user, 'https://example.com/feed');
    }

    /**
     * A user can assert format 'scraped' for a URL that really serves an XML
     * feed, poisoning the SHARED row: refresh then runs the HTML extractor
     * over RSS forever. When discovery later PROVES the URL is a direct feed
     * (a stronger fact than the first subscriber's assertion), the row heals
     * to 'xml' instead of chaining new subscribers to the broken format.
     */
    public function testDiscoveryVerifiedSubscribeHealsAScrapedPoisonedFeed(): void
    {
        $user = $this->factory()->create('healer@example.com');
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setSourceFormat('scraped');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        $service = $this->service(
            $this->discoveryReturning($this->discovered('https://example.com/feed.xml')),
        );

        $outcome = $service->subscribe($user, 'https://example.com/feed.xml');

        self::assertNotNull($outcome->subscription);
        self::assertSame('xml', $feed->getSourceFormat());
    }

    /**
     * The natural "re-add it to fix it" move by an EXISTING victim: the user is
     * already subscribed to the poisoned row, so the duplicate check aborts the
     * subscribe with AlreadySubscribedException — but the heal it triggered on
     * the way must still stick. The format change is flushed in its own step
     * before the throw, so re-reading the row from the database (after clearing
     * the identity map) shows 'xml', not the un-persisted 'scraped'.
     */
    public function testHealPersistsEvenWhenTheUserIsAlreadySubscribed(): void
    {
        $user = $this->factory()->create('reheal@example.com');
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setSourceFormat('scraped');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-06-01T00:00:00Z')));
        $this->entityManager->flush();
        $feedId = $feed->requireId();

        $service = $this->service(
            $this->discoveryReturning($this->discovered('https://example.com/feed.xml')),
        );

        try {
            $service->subscribe($user, 'https://example.com/feed.xml');
            self::fail('Expected AlreadySubscribedException');
        } catch (AlreadySubscribedException) {
            // expected: the user already holds this subscription
        }

        // Re-read from the database, not the identity map: without the in-step
        // flush the heal would be discarded here and the row would read 'scraped'.
        $this->entityManager->clear();
        $reloaded = $this->entityManager->getRepository(Feed::class)->find($feedId);
        self::assertNotNull($reloaded);
        self::assertSame('xml', $reloaded->getSourceFormat());
    }

    /**
     * The reverse direction must never flip: a 'scraped' arrival is only the
     * USER's assertion, so it cannot downgrade a row that discovery (or the
     * row's creator) established as a real feed document.
     */
    public function testScrapedSubscribeNeverDowngradesAnXmlFeed(): void
    {
        $user = $this->factory()->create('downgrader@example.com');
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $feed = new Feed('https://example.com/feed.xml'); // sourceFormat defaults to 'xml'
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        $service = $this->service(
            $this->discoveryReturning($this->discovered('https://example.com/feed.xml')),
        );

        $outcome = $service->subscribe($user, 'https://example.com/feed.xml', 'scraped');

        self::assertNotNull($outcome->subscription);
        self::assertSame('xml', $feed->getSourceFormat());
    }

    public function testDirectFeedSubscribeAttachesTheGivenTags(): void
    {
        $user = $this->factory()->create('tagger@example.com');
        $news = new Tag($user, 'News');
        $tech = new Tag($user, 'Tech');
        $this->entityManager->persist($news);
        $this->entityManager->persist($tech);
        $this->entityManager->flush();

        $service = $this->service(
            $this->discoveryReturning($this->discovered('https://example.com/feed.xml')),
        );

        $outcome = $service->subscribe($user, 'https://example.com/feed', null, [$news, $tech]);

        self::assertNotNull($outcome->subscription);
        $tagNames = array_map(
            static fn (Tag $t): string => $t->getName(),
            $outcome->subscription->getTags()->toArray(),
        );
        self::assertSame(['News', 'Tech'], $tagNames);
    }

    /**
     * The 'scraped' shortcut skips discovery, but it still runs through
     * createSubscription — so the tags picked in the add-feed form must land on
     * the row it creates, exactly as on the discovery-confirmed path.
     */
    public function testScrapedSubscribeAttachesTheGivenTags(): void
    {
        $user = $this->factory()->create('scrapedtagger@example.com');
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $blog = new Tag($user, 'Blogs');
        $this->entityManager->persist($blog);
        $this->entityManager->flush();

        $service = $this->service(
            $this->discoveryReturning($this->discovered('https://example.com/unused.xml')),
        );

        $outcome = $service->subscribe($user, 'https://example.com/page', 'scraped', [$blog]);

        self::assertNotNull($outcome->subscription);
        self::assertSame(
            ['Blogs'],
            array_map(static fn (Tag $t): string => $t->getName(), $outcome->subscription->getTags()->toArray()),
        );
    }

    /**
     * Discovery never offers a scraped candidate to an account with the
     * preference off, so a 'scraped' subscribe reaching here at all is a
     * hand-made request — exactly the bypass this guard exists to close.
     */
    public function testAScrapedSubscribeIsRefusedWhenTheUserHasScrapingDisabled(): void
    {
        $user = $this->factory()->create('scrape-off@example.com');
        $service = $this->service($this->discoveryReturning(FeedDiscoveryResultModel::candidates([])));

        $this->expectException(ScrapingDisabledException::class);

        $service->subscribe($user, 'https://example.com/blog', SourceFormat::SCRAPED);
    }

    public function testAScrapedSubscribeSucceedsWhenTheUserHasScrapingEnabled(): void
    {
        $user = $this->factory()->create('scrape-on@example.com');
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $service = $this->service($this->discoveryReturning(FeedDiscoveryResultModel::candidates([])));

        $outcome = $service->subscribe($user, 'https://example.com/blog', SourceFormat::SCRAPED);

        self::assertNotNull($outcome->subscription);
    }

    public function testWpJsonSubscribeStoresTheFormatVerbatimWithoutDiscovery(): void
    {
        $user = $this->factory()->create('wpjson@example.com');
        // Discovery must NOT run: hand it a result that would fail the assertion if used.
        $service = $this->service($this->discoveryReturning(FeedDiscoveryResultModel::candidates([])));

        $url = 'https://wp.example/wp-json/wp/v2/posts?per_page=20'
            . '&_fields=id,date_gmt,link,guid,title,content,excerpt,jetpack_featured_media_url';
        $outcome = $service->subscribe($user, $url, SourceFormat::WP_JSON, [], 'WordPress Example');

        self::assertNotNull($outcome->subscription);
        self::assertSame($url, $outcome->subscription->getFeed()->getUrl());
        self::assertSame(SourceFormat::WP_JSON, $outcome->subscription->getFeed()->getSourceFormat());
        self::assertSame('WordPress Example', $outcome->subscription->getFeed()->getTitle());
    }

    public function testWpJsonSubscribeDoesNotChangeTheTitleOfAnExistingSharedFeed(): void
    {
        $shared = new Feed('https://wp.example/wp-json/wp/v2/posts');
        $this->entityManager->persist($shared);
        $this->entityManager->flush();

        $service = $this->service($this->discoveryReturning(FeedDiscoveryResultModel::candidates([])));
        $outcome = $service->subscribe(
            $this->factory()->create('second-wpjson@example.com'),
            'https://wp.example/wp-json/wp/v2/posts',
            SourceFormat::WP_JSON,
            [],
            'WordPress Example',
        );

        self::assertNotNull($outcome->subscription);
        self::assertNull($shared->getTitle());
    }

    public function testScrapedSubscribeDoesNotSeedTheCandidateTitle(): void
    {
        $user = $this->factory()->create('scraped-title@example.com');
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $service = $this->service($this->discoveryReturning(FeedDiscoveryResultModel::candidates([])));

        $outcome = $service->subscribe(
            $user,
            'https://example.com/blog',
            SourceFormat::SCRAPED,
            [],
            'Scraped Example',
        );

        self::assertNotNull($outcome->subscription);
        self::assertNull($outcome->subscription->getFeed()->getTitle());
    }

    public function testWpJsonSubscribeNeedsNoScrapingPermission(): void
    {
        // A user with scraping disabled (the default) must still be able to
        // subscribe a wp-json candidate — the scrape gate is scraped-only.
        $user = $this->factory()->create('wpjson-nopref@example.com');
        $service = $this->service($this->discoveryReturning(FeedDiscoveryResultModel::candidates([])));

        $outcome = $service->subscribe(
            $user,
            'https://wp.example/wp-json/wp/v2/posts?per_page=20'
                . '&_fields=id,date_gmt,link,guid,title,content,excerpt,jetpack_featured_media_url',
            SourceFormat::WP_JSON,
        );

        self::assertNotNull($outcome->subscription);
    }

    /**
     * A newly tagged feed appends to the END of that tag's list: its join
     * position is one past the tag's current maximum, not a fixed 0 that would
     * float it above feeds already in the tag.
     */
    public function testNewlyTaggedFeedAppendsWithinTheTag(): void
    {
        $user = $this->factory()->create('appender@example.com');
        $tag = new Tag($user, 'Daily');
        $existingFeed = new Feed('https://existing.example.com/feed.xml');
        $existing = new Subscription($user, $existingFeed, new \DateTimeImmutable('2026-05-01T00:00:00Z'));
        $existing->addTag($tag, 0);
        $this->entityManager->persist($tag);
        $this->entityManager->persist($existingFeed);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $service = $this->service(
            $this->discoveryReturning($this->discovered('https://fresh.example.com/feed.xml')),
        );

        $outcome = $service->subscribe($user, 'https://fresh.example.com/feed', null, [$tag]);

        self::assertNotNull($outcome->subscription);
        $joins = $outcome->subscription->getSubscriptionTags();
        self::assertCount(1, $joins);
        self::assertSame(1, $joins[0]->getPosition());
    }

    public function testHtmlPageReturnsCandidatesWithoutSubscribing(): void
    {
        $user = $this->factory()->create('cand@example.com');

        $service = $this->service(
            $this->discoveryReturning(FeedDiscoveryResultModel::candidates([
                new FeedCandidateModel('https://example.com/rss.xml', 'Main', 'rss'),
            ])),
        );

        $outcome = $service->subscribe($user, 'https://example.com/blog');

        self::assertNull($outcome->subscription);
        self::assertCount(1, $outcome->candidates);

        /** @var \App\Repository\SubscriptionRepository $repo */
        $repo = $this->entityManager->getRepository(Subscription::class);
        self::assertSame(0, $repo->countForUser($user->requireId()));
    }

    public function testPerUserCapOverridesTheGlobalDefault(): void
    {
        $user = $this->factory()->create('capped@example.com', maxSubscriptions: 1);
        $service = $this->service($this->discoveryReturning(
            $this->discovered('https://example.com/a.xml'),
        ));

        $service->subscribe($user, 'https://example.com/a.xml');

        $this->expectException(SubscriptionLimitReachedException::class);
        $service->subscribe($user, 'https://example.com/b.xml');
    }
}
