<?php

declare(strict_types=1);

namespace App\Tests\Service\SavedPosts;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Feed\Factory\FeedFactory;
use App\Service\SavedPosts\Factory\SavedFeedFactory;
use App\Service\SavedPosts\SavedFeeds;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedFeedsTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('saved-feeds@example.test');
    }

    public function testTheFirstUseCreatesAnUnfetchedFeedOutsideAllItemsAndForYou(): void
    {
        $subscription = $this->savedFeeds()->of($this->user, SocialNetwork::Bluesky);

        $feed = $subscription->getFeed();
        self::assertSame(sprintf('saved:%d:bluesky', $this->user->requireId()), $feed->getUrl());
        self::assertSame(SourceFormat::SAVED, $feed->getSourceFormat());
        self::assertSame('Saved from Bluesky', $feed->getTitle());
        self::assertSame(SocialNetwork::Bluesky, $feed->getNetwork());
        self::assertFalse($subscription->isIncludeInAllItems());
        self::assertFalse($subscription->isIncludeInForYou());
        self::assertCount(0, $subscription->getTags());
    }

    public function testFeedsAndEachNetworkGetTheirOwnAndARepeatReturnsTheSame(): void
    {
        $feeds = $this->savedFeeds()->of($this->user, null);
        $mastodon = $this->savedFeeds()->of($this->user, SocialNetwork::Mastodon);
        $again = $this->savedFeeds()->of($this->user, null);

        self::assertSame('Saved from feeds', $feeds->getFeed()->getTitle());
        self::assertNull($feeds->getFeed()->getNetwork());
        self::assertSame('Saved from Mastodon', $mastodon->getFeed()->getTitle());
        self::assertSame($feeds->requireId(), $again->requireId());
        self::assertSame(2, $this->entityManager->getRepository(Subscription::class)->count(['user' => $this->user]));
    }

    public function testAFeedRowLeftWithoutItsSubscriptionIsReused(): void
    {
        $orphan = new Feed(sprintf('saved:%d', $this->user->requireId()));
        $orphan->setSourceFormat(SourceFormat::SAVED);
        $this->entityManager->persist($orphan);
        $this->entityManager->flush();

        $subscription = $this->savedFeeds()->of($this->user, null);

        self::assertSame($orphan->requireId(), $subscription->getFeed()->requireId());
    }

    public function testTheSavedFeedSitsAfterTheFeedsTheUserAlreadyHas(): void
    {
        $feed = new Feed('https://ordered.example/feed.xml');
        $this->entityManager->persist($feed);
        $existing = new Subscription($this->user, $feed, new \DateTimeImmutable('2026-10-17 12:00:00'));
        $existing->setPosition(4);
        $this->entityManager->persist($existing);
        $this->entityManager->flush();

        $subscription = $this->savedFeeds()->of($this->user, null);

        self::assertSame(5, $subscription->getPosition());
    }

    public function testAFullAccountStillGetsItsSavedFeed(): void
    {
        $this->user->setMaxSubscriptions(0);

        self::assertNotNull($this->savedFeeds()->of($this->user, null)->getId());
    }

    private function savedFeeds(): SavedFeeds
    {
        /** @var SubscriptionRepository $subscriptions */
        $subscriptions = $this->entityManager->getRepository(Subscription::class);
        /** @var FeedRepository $feeds */
        $feeds = $this->entityManager->getRepository(Feed::class);

        return new SavedFeeds(
            $subscriptions,
            $feeds,
            new SavedFeedFactory(new FeedFactory(), new NaiveUtcClock(new MockClock('2026-10-17 12:00:00', 'UTC'))),
            $this->entityManager,
        );
    }
}
