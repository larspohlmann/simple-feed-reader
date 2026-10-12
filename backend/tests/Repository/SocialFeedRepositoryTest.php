<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Tests\DbTestCase;
use App\Tests\Support\ProfileFeedSubscriptions;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\UserFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialFeedRepositoryTest extends DbTestCase
{
    private const string WRITERS = 'at://did:plc:alice/app.bsky.graph.list/3l1';

    private User $user;
    private SocialAccount $account;
    private int $feedCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('social-feeds@example.test');
        $this->account = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::blueskyOf($this->user),
        );
    }

    public function testAHomeFeedIsFoundByItsAbsentRemoteId(): void
    {
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, null);

        self::assertSame($home, SocialFeeds::repository($this->entityManager)
            ->findOneFor($this->account, SocialFeedKind::Home, null));
        self::assertNull($home->getRemoteId());
        self::assertNull(SocialFeeds::repository($this->entityManager)
            ->findOneFor($this->account, SocialFeedKind::UserList, null));
    }

    public function testAnotherAccountsHomeIsNeverFound(): void
    {
        $other = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->user));
        $this->socialFeed($other, SocialFeedKind::Home, null);

        self::assertNull(SocialFeeds::repository($this->entityManager)
            ->findOneFor($this->account, SocialFeedKind::Home, null));
    }

    public function testASecondHomeFeedOfTheSameAccountIsRefusedByTheDatabase(): void
    {
        $this->socialFeed($this->account, SocialFeedKind::Home, null);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->socialFeed($this->account, SocialFeedKind::Home, null);
    }

    public function testAListIsFoundByItsRemoteIdAndByItsFeed(): void
    {
        $list = $this->socialFeed($this->account, SocialFeedKind::UserList, self::WRITERS);

        $found = SocialFeeds::repository($this->entityManager)
            ->findOneFor($this->account, SocialFeedKind::UserList, self::WRITERS);

        self::assertSame($list, $found);
        self::assertSame($list, SocialFeeds::repository($this->entityManager)->findOneByFeed($list->getFeed()));
        self::assertSame(self::WRITERS, $list->getRemoteId());
    }

    public function testAnAccountsFeedsAndSubscriptionsAreItsOwnOnly(): void
    {
        $other = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($this->user),
        );
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, null);
        $theirs = $this->socialFeed($other, SocialFeedKind::Home, null);
        $own = $this->subscribe($home);
        $this->subscribe($theirs);
        $profileFeeds = new ProfileFeedSubscriptions($this->entityManager);
        $profileFeeds->subscribe($this->user, 'https://bsky.app/profile/did:plc:bob/rss');

        self::assertSame([$own], SocialFeeds::repository($this->entityManager)->subscriptionsOf($this->account));
        self::assertSame([$home], SocialFeeds::repository($this->entityManager)->findAllOf($this->account));
    }

    public function testSubscribedCollectionsAreTheAccountsOwnSubscribedOnesOnly(): void
    {
        $other = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($this->user),
        );
        $home = $this->subscribe($this->socialFeed($this->account, SocialFeedKind::Home, null));
        $writers = $this->subscribe($this->socialFeed($this->account, SocialFeedKind::UserList, self::WRITERS));
        $this->socialFeed($this->account, SocialFeedKind::CustomFeed, 'at://did:plc:x/app.bsky.feed.generator/news');
        $this->subscribe($this->socialFeed($other, SocialFeedKind::Home, null));

        self::assertSame(
            [
                ['kind' => SocialFeedKind::Home, 'remoteId' => null, 'subscriptionId' => $home->requireId()],
                [
                    'kind' => SocialFeedKind::UserList,
                    'remoteId' => self::WRITERS,
                    'subscriptionId' => $writers->requireId(),
                ],
            ],
            SocialFeeds::repository($this->entityManager)->subscribedCollectionsOf($this->account),
        );
    }

    public function testAnotherUsersSubscriptionToTheAccountsFeedIsNotTheAccountsOwn(): void
    {
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, null);
        $own = $this->subscribe($home);
        $this->subscribeAs($this->stranger(), $home);

        self::assertSame([$own], SocialFeeds::repository($this->entityManager)->subscriptionsOf($this->account));
        self::assertSame(
            [['kind' => SocialFeedKind::Home, 'remoteId' => null, 'subscriptionId' => $own->requireId()]],
            SocialFeeds::repository($this->entityManager)->subscribedCollectionsOf($this->account),
        );
    }

    public function testThePageNewestIdIsKeptAndAnEmptyPageKeepsTheLastOne(): void
    {
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, null);

        $home->recordNewestSeen('109');
        $home->recordNewestSeen(null);

        self::assertSame('109', $home->getNewestSeenId());
    }

    public function testDeletingTheAccountRemovesItsSocialFeeds(): void
    {
        $this->socialFeed($this->account, SocialFeedKind::Home, null);
        $accountId = $this->account->requireId();

        $this->entityManager->remove($this->account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame([], $this->entityManager->getRepository(SocialFeed::class)->findAll());
        self::assertNull($this->entityManager->find(SocialAccount::class, $accountId));
    }

    public function testDeletingTheFeedRemovesItsSocialFeed(): void
    {
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, null);

        $this->entityManager->remove($home->getFeed());
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame([], $this->entityManager->getRepository(SocialFeed::class)->findAll());
    }

    private function socialFeed(SocialAccount $account, SocialFeedKind $kind, ?string $remoteId): SocialFeed
    {
        $feed = new Feed('social:test:' . ++$this->feedCount);
        $this->entityManager->persist($feed);
        $socialFeed = new SocialFeed($account, $feed, $kind, $remoteId);
        $this->entityManager->persist($socialFeed);
        $this->entityManager->flush();

        return $socialFeed;
    }

    private function subscribe(SocialFeed $socialFeed): Subscription
    {
        return $this->subscribeAs($this->user, $socialFeed);
    }

    private function subscribeAs(User $user, SocialFeed $socialFeed): Subscription
    {
        $subscribedAt = new \DateTimeImmutable('2026-10-12 10:00:00');
        $subscription = new Subscription($user, $socialFeed->getFeed(), $subscribedAt);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    private function stranger(): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->entityManager, $hasher))->create('stranger@example.test');
    }
}
