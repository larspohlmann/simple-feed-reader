<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Exception\AlreadySubscribedException;
use App\Service\Discovery\FeedCandidate;
use App\Service\Discovery\FeedDiscoveryInterface;
use App\Service\Discovery\FeedDiscoveryResult;
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

            public function discover(string $url): FeedDiscoveryResult
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
            $this->em,
            new MockClock('2026-06-01T00:00:00Z'),
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
}
