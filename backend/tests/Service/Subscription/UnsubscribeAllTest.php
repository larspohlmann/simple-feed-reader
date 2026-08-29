<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Discovery\FeedDiscoveryInterface;
use App\Service\Discovery\ScrapeFallbackPolicy;
use App\Service\OrphanedFeedReclaimer;
use App\Service\Subscription\FirstFetchRecorder;
use App\Service\Subscription\SubscriptionCreator;
use App\Service\Subscription\SubscriptionService;
use App\Tests\Support\QueryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UnsubscribeAllTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SubscriptionService $subscriptions;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $service = self::getContainer()->get(SubscriptionService::class);
        self::assertInstanceOf(SubscriptionService::class, $service);
        $this->subscriptions = $service;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($user);

        return $user;
    }

    private function subscribe(User $user, Feed $feed): Subscription
    {
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($subscription);

        return $subscription;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
    }

    public function testRemovesEveryListedSubscriptionAndReturnsTheCount(): void
    {
        $user = $this->user('unsub-all@example.com');
        $kept = $this->subscribe($user, $this->feed('https://kept.example/feed.xml'));
        $goingOne = $this->subscribe($user, $this->feed('https://one.example/feed.xml'));
        $goingTwo = $this->subscribe($user, $this->feed('https://two.example/feed.xml'));
        $this->em->flush();

        $keptId = (int) $kept->getId();

        $removed = $this->subscriptions->unsubscribeAll([$goingOne, $goingTwo]);

        self::assertSame(2, $removed);
        $repository = self::getContainer()->get(SubscriptionRepository::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repository);
        self::assertNotNull($repository->findOneOwnedBy($keptId, (int) $user->getId()));
        self::assertCount(1, $repository->findForUserWithTags((int) $user->getId()));
    }

    public function testReclaimsAFeedNobodySubscribesToAnyMore(): void
    {
        $user = $this->user('unsub-orphan@example.com');
        $orphaned = $this->feed('https://orphan.example/feed.xml');
        $subscription = $this->subscribe($user, $orphaned);
        $this->em->flush();
        $orphanedId = (int) $orphaned->getId();

        $this->subscriptions->unsubscribeAll([$subscription]);

        // reclaim() deletes via bulk DQL, which bypasses the unit of work: the
        // Feed entity persisted above is still cached as managed. Without
        // clear(), find() would serve that stale copy instead of asking the
        // database (see OrphanedFeedReclaimerTest for the same pattern).
        $this->em->clear();
        $feeds = self::getContainer()->get(FeedRepository::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        self::assertNull($feeds->find($orphanedId), 'A feed with no subscriber left must be reclaimed.');
    }

    public function testKeepsAFeedAnotherAccountStillSubscribesTo(): void
    {
        $mine = $this->user('unsub-shared-mine@example.com');
        $theirs = $this->user('unsub-shared-theirs@example.com');
        $shared = $this->feed('https://shared.example/feed.xml');
        $ours = $this->subscribe($mine, $shared);
        $this->subscribe($theirs, $shared);
        $this->em->flush();
        $sharedId = (int) $shared->getId();

        $this->subscriptions->unsubscribeAll([$ours]);

        // Same identity-map staleness as testReclaimsAFeedNobodySubscribesToAnyMore:
        // reclaim() deletes via bulk DQL, which bypasses the unit of work, so
        // find() would serve the Feed entity persisted above instead of asking
        // the database.
        $this->em->clear();
        $feeds = self::getContainer()->get(FeedRepository::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        self::assertNotNull($feeds->find($sharedId));
    }

    /**
     * reclaim() is idempotent — a second call against an already-deleted feed
     * id returns false and issues its DELETE anyway, changing nothing. So
     * "the feed is gone" holds whether unsubscribeAll() reclaims the shared
     * feed once (per distinct id, as intended) or twice (once per removed
     * subscription); an outcome-only assertion cannot tell those apart.
     * Counting the DELETE statements reclaim() issues is the only way to pin
     * the de-duplication itself, not just its externally visible result —
     * same reasoning as RecommendationCandidateLoaderTest's empty-id-list
     * guard.
     */
    public function testTwoSubscriptionsToOneFeedReclaimItOnce(): void
    {
        $mine = $this->user('unsub-two-mine@example.com');
        $theirs = $this->user('unsub-two-theirs@example.com');
        $shared = $this->feed('https://both.example/feed.xml');
        $ours = $this->subscribe($mine, $shared);
        $alsoOurs = $this->subscribe($theirs, $shared);
        $this->em->flush();
        $sharedId = (int) $shared->getId();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $removed = $this->subscriptions->unsubscribeAll([$ours, $alsoOurs]);

        self::assertSame(2, $removed);
        self::assertCount(
            1,
            $recorder->queriesMatching('delete from feed'),
            'unsubscribeAll() must reclaim a feed shared by two removed subscriptions exactly once.',
        );
        // Same identity-map staleness as testReclaimsAFeedNobodySubscribesToAnyMore.
        $this->em->clear();
        $feeds = self::getContainer()->get(FeedRepository::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        self::assertNull($feeds->find($sharedId));
    }

    public function testAnEmptyListRemovesNothing(): void
    {
        self::assertSame(0, $this->subscriptions->unsubscribeAll([]));
    }

    /**
     * testAnEmptyListRemovesNothing only pins the return value, and
     * `\count([])` is 0 whether or not the empty-list guard runs — Doctrine's
     * own UnitOfWork::commit() already no-ops a flush with nothing scheduled,
     * so even counting executed queries cannot tell the guard apart from its
     * absence. Only a mock that would fail the test on a call to flush() or
     * remove() can: it proves the guard, not just its externally-identical
     * result.
     */
    public function testAnEmptyListNeverTouchesTheEntityManager(): void
    {
        $container = self::getContainer();
        $discovery = $container->get(FeedDiscoveryInterface::class);
        self::assertInstanceOf(FeedDiscoveryInterface::class, $discovery);
        $creator = $container->get(SubscriptionCreator::class);
        self::assertInstanceOf(SubscriptionCreator::class, $creator);
        $scrapeFallbackPolicy = $container->get(ScrapeFallbackPolicy::class);
        self::assertInstanceOf(ScrapeFallbackPolicy::class, $scrapeFallbackPolicy);
        $firstFetch = $container->get(FirstFetchRecorder::class);
        self::assertInstanceOf(FirstFetchRecorder::class, $firstFetch);
        $orphanedFeeds = $container->get(OrphanedFeedReclaimer::class);
        self::assertInstanceOf(OrphanedFeedReclaimer::class, $orphanedFeeds);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $em->expects(self::never())->method('remove');

        $service = new SubscriptionService(
            $discovery,
            $creator,
            $scrapeFallbackPolicy,
            $firstFetch,
            $orphanedFeeds,
            $em,
        );

        self::assertSame(0, $service->unsubscribeAll([]));
    }
}
