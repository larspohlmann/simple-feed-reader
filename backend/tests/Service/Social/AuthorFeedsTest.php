<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Service\Social\AuthorFeeds;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Subscription\Exception\AlreadySubscribedException;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Service\Subscription\SubscriptionCreator;
use App\Tests\DbTestCase;
use App\Tests\Support\BlueskyProfiles;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthorFeedsTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('author-feeds@example.test');
    }

    public function testAHiddenProfileBecomesATaggedAuthorFeedInAllItemsAndForYou(): void
    {
        $account = $this->account();
        $tag = $this->tag('People');
        $nesting = $this->entityManager->getConnection()->getTransactionNestingLevel();

        $subscription = $this->authorFeeds()->create($account, self::rusty(), [$tag]);

        self::assertSame($nesting, $this->entityManager->getConnection()->getTransactionNestingLevel());
        $feed = $subscription->getFeed();
        self::assertSame(
            sprintf('social:bluesky:%d:author:%s', $account->requireId(), BlueskyProfiles::RUSTY),
            $feed->getUrl(),
        );
        self::assertSame(SourceFormat::BLUESKY_API, $feed->getSourceFormat());
        self::assertSame('Rusty', $feed->getTitle());
        self::assertSame('https://bsky.app/profile/' . BlueskyProfiles::RUSTY, $feed->getSiteUrl());
        self::assertTrue($subscription->isIncludeInAllItems());
        self::assertTrue($subscription->isIncludeInForYou());
        $tagNames = array_map(static fn (Tag $each): string => $each->getName(), $subscription->getTags()->toArray());
        self::assertSame(['People'], $tagNames);
        $socialFeed = SocialFeeds::repository($this->entityManager)
            ->findOneFor($account, SocialFeedKind::Author, BlueskyProfiles::RUSTY);
        self::assertSame($feed, $socialFeed?->getFeed());
    }

    public function testSubscribingTheSameProfileTwiceIsRefused(): void
    {
        $account = $this->account();
        $this->authorFeeds()->create($account, self::rusty(), []);

        $this->expectException(AlreadySubscribedException::class);
        $this->authorFeeds()->create($account, self::rusty(), []);
    }

    public function testAProfileAlreadyReadThroughAnotherAccountOfTheUserIsRefused(): void
    {
        $this->authorFeeds()->create($this->secondAccount(), self::rusty(), []);

        try {
            $this->authorFeeds()->create($this->account(), self::rusty(), []);
            self::fail('One user follows one profile through one author feed.');
        } catch (AlreadySubscribedException) {
            self::assertSame(1, $this->entityManager->getRepository(Subscription::class)->count([]));
        }
    }

    public function testTheUsersSubscriptionToAProfileIsFoundWhicheverAccountReadsIt(): void
    {
        $subscription = $this->authorFeeds()->create($this->secondAccount(), self::rusty(), []);
        $this->account();

        self::assertSame($subscription, $this->authorFeeds()->subscriptionOf($this->user, BlueskyProfiles::RUSTY));
        self::assertNull($this->authorFeeds()->subscriptionOf($this->user, 'did:plc:someone-else'));
    }

    public function testAFullAccountGetsNoAuthorFeedAndTheEntityManagerStaysOpen(): void
    {
        $this->user->setMaxSubscriptions(0);
        $account = $this->account();
        $nesting = $this->entityManager->getConnection()->getTransactionNestingLevel();

        try {
            $this->authorFeeds()->create($account, self::rusty(), []);
            self::fail('The subscription cap must hold for author feeds too.');
        } catch (SubscriptionLimitReachedException) {
            self::assertSame($nesting, $this->entityManager->getConnection()->getTransactionNestingLevel());
            self::assertTrue($this->entityManager->isOpen());
            self::assertSame(0, $this->entityManager->getRepository(Feed::class)->count([]));
            self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
        }
    }

    public function testDisconnectingTheAccountRemovesItsAuthorFeeds(): void
    {
        $account = $this->account();
        $this->authorFeeds()->create($account, self::rusty(), []);
        $privateFeeds = PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, new MockClock());

        self::assertSame(1, $privateFeeds->removeAllOf($account));
        $this->entityManager->clear();
        self::assertSame(0, $this->entityManager->getRepository(Subscription::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
    }

    private function authorFeeds(): AuthorFeeds
    {
        $creator = self::getContainer()->get(SubscriptionCreator::class);
        self::assertInstanceOf(SubscriptionCreator::class, $creator);

        return new AuthorFeeds(SocialFeeds::repository($this->entityManager), $creator, $this->entityManager);
    }

    private function account(): SocialAccount
    {
        return SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
    }

    private function secondAccount(): SocialAccount
    {
        return SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::secondBlueskyOf($this->user));
    }

    private function tag(string $name): Tag
    {
        $tag = new Tag($this->user, $name);
        $tag->setPosition(0);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    private static function rusty(): HiddenProfileModel
    {
        return new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty');
    }
}
