<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Enum\ScrapeFallback;
use App\Enum\SourceFormat;
use App\Exception\AlreadySubscribedException;
use App\Exception\SubscriptionLimitReachedException;
use App\Service\Discovery\Exception\ScrapingDisabledException;
use App\Service\Discovery\FeedCandidate;
use App\Service\Discovery\FeedDiscoveryInterface;
use App\Service\Discovery\FeedDiscoveryResult;
use App\Service\Discovery\ScrapeFallbackPolicy;
use App\Service\OrphanedFeedReclaimer;
use App\Service\Subscription\SubscriptionLimitResolver;
use App\Service\Subscription\SubscriptionService;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriptionServiceTest extends DbTestCase
{
    private function factory(): UserFactory
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($this->em, $hasher);
    }

    /** A FeedDiscovery test double returning a fixed result. */
    private function discoveryReturning(FeedDiscoveryResult $result): FeedDiscoveryInterface
    {
        return new class ($result) implements FeedDiscoveryInterface {
            public function __construct(private readonly FeedDiscoveryResult $result)
            {
            }

            public function discover(string $url, ScrapeFallback $fallback): FeedDiscoveryResult
            {
                return $this->result;
            }
        };
    }

    private function service(FeedDiscoveryInterface $discovery): SubscriptionService
    {
        return new SubscriptionService(
            $discovery,
            $this->em->getRepository(Subscription::class),
            $this->em->getRepository(Feed::class),
            $this->em->getRepository(SubscriptionTag::class),
            $this->em,
            new MockClock('2026-06-01T00:00:00Z'),
            new SubscriptionLimitResolver(),
            new ScrapeFallbackPolicy(),
            new OrphanedFeedReclaimer($this->em),
        );
    }

    public function testDirectFeedCreatesFeedAndSubscription(): void
    {
        $user = $this->factory()->create('sub@example.com');

        $service = $this->service(
            $this->discoveryReturning(FeedDiscoveryResult::directFeed('https://example.com/feed.xml')),
        );

        $outcome = $service->subscribe($user, 'https://example.com/feed');

        self::assertNotNull($outcome->subscription);
        self::assertSame('https://example.com/feed.xml', $outcome->subscription->getFeed()->getUrl());
        self::assertSame([], $outcome->candidates);
    }

    public function testSecondSubscriptionToSameFeedIsRejected(): void
    {
        $user = $this->factory()->create('dupe@example.com');

        $service = $this->service(
            $this->discoveryReturning(FeedDiscoveryResult::directFeed('https://example.com/feed.xml')),
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
        $this->em->persist($feed);
        $this->em->flush();

        $service = $this->service(
            $this->discoveryReturning(FeedDiscoveryResult::directFeed('https://example.com/feed.xml')),
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
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-06-01T00:00:00Z')));
        $this->em->flush();
        $feedId = (int) $feed->getId();

        $service = $this->service(
            $this->discoveryReturning(FeedDiscoveryResult::directFeed('https://example.com/feed.xml')),
        );

        try {
            $service->subscribe($user, 'https://example.com/feed.xml');
            self::fail('Expected AlreadySubscribedException');
        } catch (AlreadySubscribedException) {
            // expected: the user already holds this subscription
        }

        // Re-read from the database, not the identity map: without the in-step
        // flush the heal would be discarded here and the row would read 'scraped'.
        $this->em->clear();
        $reloaded = $this->em->getRepository(Feed::class)->find($feedId);
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
        $this->em->persist($feed);
        $this->em->flush();

        $service = $this->service(
            $this->discoveryReturning(FeedDiscoveryResult::directFeed('https://example.com/feed.xml')),
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
        $this->em->persist($news);
        $this->em->persist($tech);
        $this->em->flush();

        $service = $this->service(
            $this->discoveryReturning(FeedDiscoveryResult::directFeed('https://example.com/feed.xml')),
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
        $this->em->persist($blog);
        $this->em->flush();

        $service = $this->service(
            $this->discoveryReturning(FeedDiscoveryResult::directFeed('https://example.com/unused.xml')),
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
        $service = $this->service($this->discoveryReturning(FeedDiscoveryResult::candidates([])));

        $this->expectException(ScrapingDisabledException::class);

        $service->subscribe($user, 'https://example.com/blog', SourceFormat::SCRAPED);
    }

    public function testAScrapedSubscribeSucceedsWhenTheUserHasScrapingEnabled(): void
    {
        $user = $this->factory()->create('scrape-on@example.com');
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $service = $this->service($this->discoveryReturning(FeedDiscoveryResult::candidates([])));

        $outcome = $service->subscribe($user, 'https://example.com/blog', SourceFormat::SCRAPED);

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
        $this->em->persist($tag);
        $this->em->persist($existingFeed);
        $this->em->persist($existing);
        $this->em->flush();

        $service = $this->service(
            $this->discoveryReturning(FeedDiscoveryResult::directFeed('https://fresh.example.com/feed.xml')),
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
            $this->discoveryReturning(FeedDiscoveryResult::candidates([
                new FeedCandidate('https://example.com/rss.xml', 'Main', 'rss'),
            ])),
        );

        $outcome = $service->subscribe($user, 'https://example.com/blog');

        self::assertNull($outcome->subscription);
        self::assertCount(1, $outcome->candidates);

        /** @var \App\Repository\SubscriptionRepository $repo */
        $repo = $this->em->getRepository(Subscription::class);
        self::assertSame(0, $repo->countForUser((int) $user->getId()));
    }

    public function testPerUserCapOverridesTheGlobalDefault(): void
    {
        $user = $this->factory()->create('capped@example.com', maxSubscriptions: 1);
        $service = $this->service($this->discoveryReturning(
            FeedDiscoveryResult::directFeed('https://example.com/a.xml'),
        ));

        $service->subscribe($user, 'https://example.com/a.xml');

        $this->expectException(SubscriptionLimitReachedException::class);
        $service->subscribe($user, 'https://example.com/b.xml');
    }
}
