<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;
use App\Tests\DbTestCase;
use App\Tests\Support\ProfileFeedSubscriptions;
use App\Tests\Support\QueryRecorder;
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

    public function testAHomeFeedIsFoundByItsEmptyRemoteId(): void
    {
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, '');

        self::assertSame($home, SocialFeeds::repository($this->entityManager)
            ->findOneFor($this->account, SocialFeedKind::Home, ''));
        self::assertSame('', $home->getRemoteId());
        self::assertNull(SocialFeeds::repository($this->entityManager)
            ->findOneFor($this->account, SocialFeedKind::UserList, ''));
    }

    public function testAnotherAccountsHomeIsNeverFound(): void
    {
        $other = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->user));
        $this->socialFeed($other, SocialFeedKind::Home, '');

        self::assertNull(SocialFeeds::repository($this->entityManager)
            ->findOneFor($this->account, SocialFeedKind::Home, ''));
    }

    public function testASecondHomeFeedOfTheSameAccountIsRefusedByTheDatabase(): void
    {
        $this->socialFeed($this->account, SocialFeedKind::Home, '');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->socialFeed($this->account, SocialFeedKind::Home, '');
    }

    public function testAListIsFoundByItsRemoteId(): void
    {
        $list = $this->socialFeed($this->account, SocialFeedKind::UserList, self::WRITERS);

        $found = SocialFeeds::repository($this->entityManager)
            ->findOneFor($this->account, SocialFeedKind::UserList, self::WRITERS);

        self::assertSame($list, $found);
        self::assertSame(self::WRITERS, $list->getRemoteId());
    }

    public function testPrivateFeedsAreLoadedByTheirFeedIdsWithTheirAccountsInOneQuery(): void
    {
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, '');
        $list = $this->socialFeed($this->account, SocialFeedKind::UserList, self::WRITERS);
        $homeFeedId = $home->getFeed()->requireId();
        $listFeedId = $list->getFeed()->requireId();
        $this->entityManager->clear();
        $recorder = $this->recorder();

        $found = SocialFeeds::repository($this->entityManager)->findWithAccountByFeedIds([$listFeedId, $homeFeedId]);
        ksort($found);

        self::assertSame([$homeFeedId, $listFeedId], array_keys($found));
        self::assertSame(self::WRITERS, $found[$listFeedId]->getRemoteId());
        self::assertSame(SocialNetwork::Bluesky, $found[$homeFeedId]->getSocialAccount()->getNetwork());
        self::assertCount(1, $recorder->queries());
    }

    public function testNoFeedIdsAskNothing(): void
    {
        $recorder = $this->recorder();

        self::assertSame([], SocialFeeds::repository($this->entityManager)->findWithAccountByFeedIds([]));
        self::assertSame([], $recorder->queries());
    }

    private function recorder(): QueryRecorder
    {
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        return $recorder;
    }

    public function testAnAccountsFeedsAndSubscriptionsAreItsOwnOnly(): void
    {
        $other = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonOf($this->user),
        );
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, '');
        $theirs = $this->socialFeed($other, SocialFeedKind::Home, '');
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
        $home = $this->subscribe($this->socialFeed($this->account, SocialFeedKind::Home, ''));
        $writers = $this->subscribe($this->socialFeed($this->account, SocialFeedKind::UserList, self::WRITERS));
        $this->socialFeed($this->account, SocialFeedKind::CustomFeed, 'at://did:plc:x/app.bsky.feed.generator/news');
        $this->subscribe($this->socialFeed($other, SocialFeedKind::Home, ''));

        self::assertSame(
            [
                ['kind' => SocialFeedKind::Home, 'remoteId' => '', 'subscriptionId' => $home->requireId()],
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
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, '');
        $own = $this->subscribe($home);
        $this->subscribeAs($this->stranger(), $home);

        self::assertSame([$own], SocialFeeds::repository($this->entityManager)->subscriptionsOf($this->account));
        self::assertSame(
            [['kind' => SocialFeedKind::Home, 'remoteId' => '', 'subscriptionId' => $own->requireId()]],
            SocialFeeds::repository($this->entityManager)->subscribedCollectionsOf($this->account),
        );
    }

    public function testThePageNewestIdIsKeptAndAnEmptyPageKeepsTheLastOne(): void
    {
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, '');

        $home->recordNewestSeen('109');
        $home->recordNewestSeen(null);

        self::assertSame('109', $home->getNewestSeenId());
    }

    public function testDeletingTheAccountRemovesItsSocialFeeds(): void
    {
        $this->socialFeed($this->account, SocialFeedKind::Home, '');
        $accountId = $this->account->requireId();

        $this->entityManager->remove($this->account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame([], $this->entityManager->getRepository(SocialFeed::class)->findAll());
        self::assertNull($this->entityManager->find(SocialAccount::class, $accountId));
    }

    public function testDeletingTheFeedRemovesItsSocialFeed(): void
    {
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, '');

        $this->entityManager->remove($home->getFeed());
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame([], $this->entityManager->getRepository(SocialFeed::class)->findAll());
    }

    public function testAPrivateFeedIsFoundByItsFeedAndAnOrdinaryFeedIsNot(): void
    {
        $home = $this->socialFeed($this->account, SocialFeedKind::Home, '');
        $ordinary = new Feed('https://example.com/ordinary.xml');
        $this->entityManager->persist($ordinary);
        $this->entityManager->flush();

        self::assertSame($home, SocialFeeds::repository($this->entityManager)->forFeed($home->getFeed()));
        self::assertNull(SocialFeeds::repository($this->entityManager)->forFeed($ordinary));
    }

    private function socialFeed(SocialAccount $account, SocialFeedKind $kind, string $remoteId): SocialFeed
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
