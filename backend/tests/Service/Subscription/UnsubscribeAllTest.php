<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Discovery\FeedDiscovery\FeedDiscoveryInterface;
use App\Service\Discovery\ScrapeFallbackPolicy;
use App\Service\Feed\OrphanedFeedReclaimer;
use App\Service\Subscription\FirstFetchRecorder;
use App\Service\Subscription\SubscriptionCreator;
use App\Service\Subscription\SubscriptionService;
use App\Tests\Support\QueryRecorder;
use App\Tests\Support\SeedsUsers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UnsubscribeAllTest extends KernelTestCase
{
    use SeedsUsers;

    private EntityManagerInterface $entityManager;
    private SubscriptionService $subscriptions;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $service = self::getContainer()->get(SubscriptionService::class);
        self::assertInstanceOf(SubscriptionService::class, $service);
        $this->subscriptions = $service;
    }

    private function subscribe(User $user, Feed $feed): Subscription
    {
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->entityManager->persist($subscription);

        return $subscription;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    public function testRemovesEveryListedSubscriptionAndReturnsTheCount(): void
    {
        $user = $this->user('unsub-all@example.com');
        $kept = $this->subscribe($user, $this->feed('https://kept.example/feed.xml'));
        $goingOne = $this->subscribe($user, $this->feed('https://one.example/feed.xml'));
        $goingTwo = $this->subscribe($user, $this->feed('https://two.example/feed.xml'));
        $this->entityManager->flush();

        $keptId = $kept->requireId();

        $removed = $this->subscriptions->unsubscribeAll([$goingOne, $goingTwo]);

        self::assertSame(2, $removed);
        $repository = self::getContainer()->get(SubscriptionRepository::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repository);
        self::assertSame($keptId, $repository->getOneForUser($user->requireId(), $keptId)->requireId());
        self::assertCount(1, $repository->findForUserWithTags($user->requireId()));
    }

    public function testReclaimsAFeedNobodySubscribesToAnyMore(): void
    {
        $user = $this->user('unsub-orphan@example.com');
        $orphaned = $this->feed('https://orphan.example/feed.xml');
        $subscription = $this->subscribe($user, $orphaned);
        $this->entityManager->flush();
        $orphanedId = $orphaned->requireId();

        $this->subscriptions->unsubscribeAll([$subscription]);

        // reclaim() deletes by bulk DQL, past the unit of work: without clear(), find() serves the stale managed Feed.
        $this->entityManager->clear();
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
        $this->entityManager->flush();
        $sharedId = $shared->requireId();

        $this->subscriptions->unsubscribeAll([$ours]);

        // Same identity-map staleness as testReclaimsAFeedNobodySubscribesToAnyMore.
        $this->entityManager->clear();
        $feeds = self::getContainer()->get(FeedRepository::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        self::assertNotNull($feeds->find($sharedId));
    }

    /**
     * reclaim() is idempotent, so "the feed is gone" holds whether the shared feed is reclaimed once or twice: only
     * the DELETE count pins the de-duplication.
     */
    public function testTwoSubscriptionsToOneFeedReclaimItOnce(): void
    {
        $mine = $this->user('unsub-two-mine@example.com');
        $theirs = $this->user('unsub-two-theirs@example.com');
        $shared = $this->feed('https://both.example/feed.xml');
        $ours = $this->subscribe($mine, $shared);
        $alsoOurs = $this->subscribe($theirs, $shared);
        $this->entityManager->flush();
        $sharedId = $shared->requireId();

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
        $this->entityManager->clear();
        $feeds = self::getContainer()->get(FeedRepository::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        self::assertNull($feeds->find($sharedId));
    }

    public function testAnEmptyListRemovesNothing(): void
    {
        self::assertSame(0, $this->subscriptions->unsubscribeAll([]));
    }

    /**
     * Doctrine already skips an empty flush, so neither the return value nor a query count sees the empty-list guard;
     * only a mock that fails on flush() or remove() does.
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

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');
        $entityManager->expects($this->never())->method('remove');

        $service = new SubscriptionService(
            $discovery,
            $creator,
            $scrapeFallbackPolicy,
            $firstFetch,
            $orphanedFeeds,
            $entityManager,
        );

        self::assertSame(0, $service->unsubscribeAll([]));
    }
}
