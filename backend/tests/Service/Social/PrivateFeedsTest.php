<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\KnownSocialCollection;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Repository\KnownSocialCollectionRepository;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\PrivateFeedOutcome;
use App\Service\Social\PrivateFeeds;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use App\Tests\DbTestCase;
use App\Tests\Support\BlueskyProfiles;
use App\Tests\Support\PrivateFeedsWiring;
use App\Tests\Support\ProfileFeedSubscriptions;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PrivateFeedsTest extends DbTestCase
{
    private MockClock $clock;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-12 12:00:00', 'UTC');

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('private-feeds@example.test');
    }

    public function testTheHomeTimelineBecomesAPrivateFeedOutsideAllItemsAndForYou(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));

        $outcome = $this->privateFeeds()->create($account, PrivateFeedModel::home());

        self::assertSame(PrivateFeedOutcome::Created, $outcome);
        $subscription = $this->onlySubscription();
        $feed = $subscription->getFeed();
        self::assertSame(sprintf('social:bluesky:%d:home', $account->requireId()), $feed->getUrl());
        self::assertSame(SourceFormat::BLUESKY_API, $feed->getSourceFormat());
        self::assertSame('Home', $feed->getTitle());
        self::assertSame('https://bsky.app/profile/did:plc:alice', $feed->getSiteUrl());
        self::assertFalse($subscription->isIncludeInAllItems());
        self::assertFalse($subscription->isIncludeInForYou());
        self::assertCount(0, $subscription->getTags());
        $socialFeed = SocialFeeds::repository($this->entityManager)->findWithAccountByFeedIds([$feed->requireId()])[
            $feed->requireId()
        ] ?? null;
        self::assertNotNull($socialFeed);
        self::assertSame(SocialFeedKind::Home, $socialFeed->getKind());
        self::assertSame($account->requireId(), $socialFeed->getSocialAccount()->requireId());
    }

    public function testCreatingItAgainChangesNothing(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $this->privateFeeds()->create($account, PrivateFeedModel::home());

        $outcome = $this->privateFeeds()->create($account, PrivateFeedModel::home());

        self::assertSame(PrivateFeedOutcome::AlreadySubscribed, $outcome);
        self::assertSame(1, $this->entityManager->getRepository(Subscription::class)->count([]));
        self::assertSame(1, $this->entityManager->getRepository(SocialFeed::class)->count([]));
    }

    public function testCreatingTheSameListTwiceRemembersItOnce(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $this->privateFeeds()->create($account, PrivateFeedModel::userList('at://list/news', 'News'));

        $outcome = $this->privateFeeds()->create($account, PrivateFeedModel::userList('at://list/news', 'News'));

        self::assertSame(PrivateFeedOutcome::AlreadySubscribed, $outcome);
        self::assertSame(1, $this->entityManager->getRepository(KnownSocialCollection::class)->count([]));
    }

    public function testAMastodonListLinksToTheListOnItsInstance(): void
    {
        $account = $this->stored(SocialAccounts::mastodonOf($this->user));

        $this->privateFeeds()->create($account, PrivateFeedModel::userList('42', 'Friends'));

        $feed = $this->onlySubscription()->getFeed();
        self::assertSame(sprintf('social:mastodon:%d:list:42', $account->requireId()), $feed->getUrl());
        self::assertSame(SourceFormat::MASTODON_API, $feed->getSourceFormat());
        self::assertSame('Friends', $feed->getTitle());
        self::assertSame('https://mastodon.example/lists/42', $feed->getSiteUrl());
    }

    public function testAFullAccountGetsNoPrivateFeedAndNothingIsWritten(): void
    {
        $this->user->setMaxSubscriptions(0);
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));

        try {
            $this->privateFeeds()->create($account, PrivateFeedModel::home());
            self::fail('The subscription cap must hold for private feeds too.');
        } catch (SubscriptionLimitReachedException) {
            self::assertSame(0, $this->entityManager->getRepository(Feed::class)->count([]));
            self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
        }
    }

    public function testAFailedSocialFeedWriteLeavesNoFeedOrSubscriptionBehind(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $failing = new FlushFailingEntityManager($this->entityManager);
        $privateFeeds = PrivateFeedsWiring::build(self::getContainer(), $failing, $this->clock);

        try {
            $privateFeeds->create($account, PrivateFeedModel::home());
            self::fail('The failing flush must surface.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->entityManager->getRepository(Feed::class)->count([]));
            self::assertSame(0, $this->entityManager->getRepository(Subscription::class)->count([]));
            self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
        }
    }

    public function testTheCapExceptionLeavesTheEntityManagerUsable(): void
    {
        $this->user->setMaxSubscriptions(0);
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        try {
            $this->privateFeeds()->create($account, PrivateFeedModel::home());
            self::fail('The cap must hold.');
        } catch (SubscriptionLimitReachedException) {
            self::assertTrue($this->entityManager->isOpen());
        }
        $this->user->setMaxSubscriptions(5);

        $outcome = $this->privateFeeds()->create($account, PrivateFeedModel::home());

        self::assertSame(PrivateFeedOutcome::Created, $outcome);
    }

    public function testACapExceptionCaughtInsideAnOuterTransactionKeepsTheEarlierFeeds(): void
    {
        $this->user->setMaxSubscriptions(1);
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));

        $this->entityManager->wrapInTransaction(function () use ($account): void {
            $this->privateFeeds()->create($account, PrivateFeedModel::home());
            try {
                $this->privateFeeds()->create(
                    $account,
                    PrivateFeedModel::userList('at://did:plc:bob/app.bsky.graph.list/1', 'Writers'),
                );
                self::fail('The cap must hold.');
            } catch (SubscriptionLimitReachedException) {
                self::assertTrue($this->entityManager->isOpen());
            }
        });

        $this->entityManager->clear();
        self::assertSame(1, $this->entityManager->getRepository(Subscription::class)->count([]));
        self::assertSame(1, $this->entityManager->getRepository(SocialFeed::class)->count([]));
    }

    public function testRemovingAnAccountsPrivateFeedsKeepsItsOtherSubscriptions(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $this->privateFeeds()->create($account, PrivateFeedModel::home());
        $this->privateFeeds()->create(
            $account,
            PrivateFeedModel::customFeed('at://did:plc:bob/app.bsky.feed.generator/news', 'News'),
        );
        $follow = (new ProfileFeedSubscriptions($this->entityManager))
            ->subscribe($this->user, 'https://bsky.app/profile/did:plc:bob/rss');

        $removed = $this->privateFeeds()->removeAllOf($account);

        self::assertSame(2, $removed);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Subscription::class, $follow->requireId()));
        self::assertSame(1, $this->entityManager->getRepository(Subscription::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
        self::assertNull($this->entityManager->getRepository(Feed::class)->findOneBy([
            'url' => sprintf('social:bluesky:%d:home', $account->requireId()),
        ]));
    }

    public function testResumingMakesEveryPrivateFeedDueNow(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $this->privateFeeds()->create($account, PrivateFeedModel::home());
        $feed = $this->onlySubscription()->getFeed();
        $feed->scheduleNextFetchAt(new \DateTimeImmutable('2026-10-12 18:00:00'));
        $this->entityManager->flush();

        $this->privateFeeds()->resume($account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Feed::class, $feed->requireId());
        self::assertSame('2026-10-12 12:00:00', $reloaded?->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testAListOrCustomFeedIsRememberedAndHomeIsNot(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));

        $this->privateFeeds()->create($account, PrivateFeedModel::home());
        $this->privateFeeds()->create(
            $account,
            PrivateFeedModel::userList('at://did:plc:alice/app.bsky.graph.list/1', 'One'),
        );
        $this->privateFeeds()->create(
            $account,
            PrivateFeedModel::customFeed('at://did:plc:bob/app.bsky.feed.generator/f', 'F'),
        );

        /** @var KnownSocialCollectionRepository $known */
        $known = $this->entityManager->getRepository(KnownSocialCollection::class);
        self::assertEqualsCanonicalizing(
            [
                'list:at://did:plc:alice/app.bsky.graph.list/1' => true,
                'custom_feed:at://did:plc:bob/app.bsky.feed.generator/f' => true,
            ],
            $known->keysOf($account),
        );
    }

    public function testAHiddenProfileBecomesATaggedAuthorFeedInAllItemsAndForYou(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $tag = $this->tag('People');
        $nesting = $this->entityManager->getConnection()->getTransactionNestingLevel();

        $subscription = $this->privateFeeds()->subscribe($account, self::rusty(), [$tag]);

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
        self::assertSame(0, $this->entityManager->getRepository(KnownSocialCollection::class)->count([]));
    }

    public function testAProfileAlreadyReadThroughAnotherAccountOfTheUserIsAlreadySubscribed(): void
    {
        $this->privateFeeds()->create($this->stored(SocialAccounts::secondBlueskyOf($this->user)), self::rusty());

        $outcome = $this->privateFeeds()->create($this->stored(SocialAccounts::blueskyOf($this->user)), self::rusty());

        self::assertSame(PrivateFeedOutcome::AlreadySubscribed, $outcome);
        self::assertSame(1, $this->entityManager->getRepository(Subscription::class)->count([]));
    }

    public function testTheUsersSubscriptionToAProfileIsFoundWhicheverAccountReadsIt(): void
    {
        $second = $this->stored(SocialAccounts::secondBlueskyOf($this->user));
        $subscription = $this->privateFeeds()->subscribe($second, self::rusty(), []);
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));

        self::assertSame($subscription, $this->privateFeeds()->subscriptionOf($account, self::rusty()));
        self::assertNull(
            $this->privateFeeds()->subscriptionOf($account, PrivateFeedModel::author('did:plc:someone-else', 'X')),
        );
    }

    public function testACollectionIsTheUsersOnlyThroughTheAccountThatReadsIt(): void
    {
        $second = $this->stored(SocialAccounts::secondBlueskyOf($this->user));
        $subscription = $this->privateFeeds()->subscribe($second, PrivateFeedModel::home(), []);
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));

        self::assertNull($this->privateFeeds()->subscriptionOf($account, PrivateFeedModel::home()));
        self::assertSame($subscription, $this->privateFeeds()->subscriptionOf($second, PrivateFeedModel::home()));
    }

    public function testAFullAccountGetsNoAuthorFeedAndTheEntityManagerStaysOpen(): void
    {
        $this->user->setMaxSubscriptions(0);
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $nesting = $this->entityManager->getConnection()->getTransactionNestingLevel();

        try {
            $this->privateFeeds()->subscribe($account, self::rusty(), []);
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
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $this->privateFeeds()->subscribe($account, self::rusty(), []);

        self::assertSame(1, $this->privateFeeds()->removeAllOf($account));
        $this->entityManager->clear();
        self::assertSame(0, $this->entityManager->getRepository(Subscription::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count([]));
    }

    public function testAnAuthorFeedInPlaceOfAnotherSubscriptionKeepsItsSettings(): void
    {
        $account = $this->stored(SocialAccounts::blueskyOf($this->user));
        $previous = (new ProfileFeedSubscriptions($this->entityManager))
            ->subscribe($this->user, 'https://bsky.app/profile/' . BlueskyProfiles::RUSTY . '/rss');
        $previous->addTag($this->tag('People'), 0);
        $previous->setIncludeInForYou(false);
        $this->entityManager->flush();

        $subscription = $this->privateFeeds()->subscribeInPlaceOf($account, self::rusty(), $previous);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Subscription::class, $subscription->requireId());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isIncludeInForYou());
        $tagNames = array_map(static fn (Tag $each): string => $each->getName(), $reloaded->getTags()->toArray());
        self::assertSame(['People'], $tagNames);
    }

    private function privateFeeds(): PrivateFeeds
    {
        return PrivateFeedsWiring::build(self::getContainer(), $this->entityManager, $this->clock);
    }

    private function stored(SocialAccount $account): SocialAccount
    {
        return SocialFeeds::persistedAccount($this->entityManager, $account);
    }

    private function tag(string $name): Tag
    {
        $tag = new Tag($this->user, $name);
        $tag->setPosition(0);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    private static function rusty(): PrivateFeedModel
    {
        return PrivateFeedModel::author(BlueskyProfiles::RUSTY, 'Rusty');
    }

    private function onlySubscription(): Subscription
    {
        $subscriptions = $this->entityManager->getRepository(Subscription::class)->findAll();
        self::assertCount(1, $subscriptions);

        return $subscriptions[0];
    }
}
