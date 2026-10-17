<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\KnownSocialCollection;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Exception\InvalidSelectionException;
use App\Repository\EntryStateRepository;
use App\Repository\KnownSocialCollectionRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Social\Exception\InvalidSocialImportFeedException;
use App\Service\Social\FollowAvatarCache;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Social\Model\SocialImportModel;
use App\Service\Social\SocialImporter;
use App\Tests\DbTestCase;
use App\Tests\Support\ProfileFeedSubscriptions;
use App\Tests\Support\SavedPostFixtures;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubHiddenProfiles;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialImporterTest extends DbTestCase
{
    private const string NEWS = 'at://did:plc:bob/app.bsky.feed.generator/news';

    private User $user;

    private ProfileFeedSubscriptions $subscriptions;

    private UserFactory $userFactory;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->userFactory = new UserFactory($this->entityManager, $hasher);
        $this->user = $this->userFactory->create('social-importer@example.test');
        $this->subscriptions = new ProfileFeedSubscriptions($this->entityManager);
        $followAvatars = self::getContainer()->get('social.follow_avatars.cache');
        self::assertInstanceOf(CacheItemPoolInterface::class, $followAvatars);
        $followAvatars->clear();
    }

    private function followAvatars(): FollowAvatarCache
    {
        $cache = self::getContainer()->get(FollowAvatarCache::class);
        self::assertInstanceOf(FollowAvatarCache::class, $cache);

        return $cache;
    }

    private function importer(): SocialImporter
    {
        $importer = self::getContainer()->get(SocialImporter::class);
        self::assertInstanceOf(SocialImporter::class, $importer);

        return $importer;
    }

    private function subscribedId(User $user, string $feedUrl): int
    {
        return $this->subscriptions->subscribe($user, $feedUrl)->requireId();
    }

    private function feedAt(string $feedUrl): ?Feed
    {
        return $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => $feedUrl]);
    }

    /** @return list<string> */
    private function tagNamesOf(string $url): array
    {
        $this->entityManager->clear();
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);
        self::assertInstanceOf(SubscriptionRepository::class, $subscriptions);
        foreach ($subscriptions->findForUserWithTags($this->user->requireId()) as $subscription) {
            if ($subscription->getFeed()->getUrl() === $url) {
                return array_values(array_map(
                    static fn (Tag $tag): string => $tag->getName(),
                    $subscription->getTags()->toArray(),
                ));
            }
        }
        self::fail('No subscription to ' . $url);
    }

    public function testChosenFollowsBecomeUntaggedSubscriptionsWithTheirTitle(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $result = $this->importer()->import($account, new SocialImportModel([
            new SocialImportFeedModel('did:plc:bob', 'Bob'),
            new SocialImportFeedModel('did:plc:carol', null),
        ], []));

        self::assertSame(2, $result->imported);
        self::assertSame(0, $result->removed);
        self::assertSame([], $this->tagNamesOf('https://bsky.app/profile/did:plc:bob/rss'));
        self::assertSame('Bob', $this->feedAt('https://bsky.app/profile/did:plc:bob/rss')?->getTitle());
    }

    public function testAnImportedProfileFeedTakesTheAvatarThePickerShowed(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $this->followAvatars()->remember($account, [self::follow('did:plc:bob', 'https://cdn.test/bob.jpg')]);

        $this->importer()->import($account, new SocialImportModel(
            [new SocialImportFeedModel('did:plc:bob', 'Bob'), new SocialImportFeedModel('did:plc:carol', 'Carol')],
            [],
            [],
        ));

        $bob = $this->feedAt(self::profileFeed('did:plc:bob'));
        self::assertSame('https://cdn.test/bob.jpg', $bob?->getImageUrl());
        self::assertNotNull($bob->getImageCheckedAt());
        $carol = $this->feedAt(self::profileFeed('did:plc:carol'));
        self::assertNotNull($carol);
        self::assertNull($carol->getImageUrl());
        self::assertNull($carol->getImageCheckedAt());
    }

    public function testAnAvatarLongerThanAFeedImageCanHoldIsLeftToTheSweep(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $this->followAvatars()->remember(
            $account,
            [self::follow('did:plc:bob', 'https://cdn.test/' . str_repeat('a', Feed::IMAGE_URL_MAX))],
        );

        $this->importer()->import(
            $account,
            new SocialImportModel([new SocialImportFeedModel('did:plc:bob', 'Bob')], [], []),
        );

        $bob = $this->feedAt(self::profileFeed('did:plc:bob'));
        self::assertNotNull($bob);
        self::assertNull($bob->getImageUrl());
        self::assertNull($bob->getImageCheckedAt());
    }

    public function testAFeedTheSweepAlreadyCheckedKeepsItsPictureWhileTheNextTakesItsAvatar(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $other = $this->userFactory->create('social-importer-other@example.test');
        $this->subscriptions->subscribe($other, self::profileFeed('did:plc:bob'));
        $checked = new \DateTimeImmutable('2026-10-01 00:00:00');
        $this->feedAt(self::profileFeed('did:plc:bob'))?->recordImageCheck('https://cdn.test/old.jpg', $checked);
        $this->entityManager->flush();
        $this->followAvatars()->remember($account, [
            self::follow('did:plc:bob', 'https://cdn.test/bob.jpg'),
            self::follow('did:plc:carol', 'https://cdn.test/carol.jpg'),
        ]);

        $this->importer()->import($account, new SocialImportModel(
            [new SocialImportFeedModel('did:plc:bob', 'Bob'), new SocialImportFeedModel('did:plc:carol', 'Carol')],
            [],
            [],
        ));

        $feed = $this->feedAt(self::profileFeed('did:plc:bob'));
        self::assertSame('https://cdn.test/old.jpg', $feed?->getImageUrl());
        self::assertEquals($checked, $feed->getImageCheckedAt());
        $carol = $this->feedAt(self::profileFeed('did:plc:carol'));
        self::assertSame('https://cdn.test/carol.jpg', $carol?->getImageUrl());
    }

    public function testAnExistingSubscriptionIsNotRetagged(): void
    {
        $this->subscriptions->subscribeTagged($this->user, 'https://mastodon.example/@bob.rss', 'Friends');

        $account = $this->storedAccount(SocialAccounts::mastodonOf($this->user));
        $result = $this->importer()->import($account, new SocialImportModel([
            new SocialImportFeedModel('bob@mastodon.example', 'Bob'),
        ], []));

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
        self::assertSame(['Friends'], $this->tagNamesOf('https://mastodon.example/@bob.rss'));
    }

    public function testAFeedThatIsNoProfileFeedOfTheAccountsNetworkRefusesTheWholeImport(): void
    {
        $keptId = $this->subscribedId($this->user, 'https://bsky.app/profile/did:plc:gone/rss');

        try {
            $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
            $this->importer()->import($account, new SocialImportModel([
                new SocialImportFeedModel('did:plc:bob', 'Bob'),
                new SocialImportFeedModel('bob@mastodon.example', 'Bob'),
            ], [$keptId]));
            self::fail('A foreign feed must refuse the import.');
        } catch (InvalidSocialImportFeedException) {
            $this->entityManager->clear();
            self::assertNotNull($this->entityManager->find(Subscription::class, $keptId));
            self::assertNull($this->feedAt('https://bsky.app/profile/did:plc:bob/rss'));
        }
    }

    public function testChosenSubscriptionsAreRemoved(): void
    {
        $goneId = $this->subscribedId($this->user, 'https://bsky.app/profile/did:plc:gone/rss');

        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));

        $result = $this->importer()->import($account, new SocialImportModel([], [$goneId]));

        self::assertSame(1, $result->removed);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Subscription::class, $goneId));
    }

    public function testAnotherUsersSubscriptionCannotBeRemoved(): void
    {
        $stranger = $this->userFactory->create('social-importer-stranger@example.test');
        $theirsId = $this->subscribedId($stranger, 'https://bsky.app/profile/did:plc:theirs/rss');

        try {
            $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
            $this->importer()->import($account, new SocialImportModel([], [$theirsId]));
            self::fail('Another user\'s subscription must not be removable.');
        } catch (InvalidSelectionException) {
            $this->entityManager->clear();
            self::assertNotNull($this->entityManager->find(Subscription::class, $theirsId));
        }
    }

    public function testRemovalsFreeSlotsForTheSubscriptionsImportedInTheSameRun(): void
    {
        $this->user->setMaxSubscriptions(1);
        $goneId = $this->subscribedId($this->user, 'https://bsky.app/profile/did:plc:gone/rss');

        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $result = $this->importer()->import($account, new SocialImportModel([
            new SocialImportFeedModel('did:plc:bob', 'Bob'),
        ], [$goneId]));

        self::assertSame(1, $result->imported);
        self::assertSame(0, $result->skippedOverLimit);
        self::assertSame(1, $result->removed);
    }

    public function testChosenCollectionsBecomePrivateFeedsCountedAsImported(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));

        $result = $this->importer()->import($account, new SocialImportModel([], [], [
            PrivateFeedModel::home(),
            PrivateFeedModel::customFeed(self::NEWS, 'News'),
        ]));

        self::assertSame(2, $result->imported);
        $feed = $this->feedAt(sprintf('social:bluesky:%d:feed:%s', $account->requireId(), self::NEWS));
        self::assertNotNull($feed);
        self::assertSame(SourceFormat::BLUESKY_API, $feed->getSourceFormat());
        self::assertSame('News', $feed->getTitle());
    }

    public function testAnAlreadyAddedCollectionCountsAsAlreadySubscribed(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $this->importer()->import($account, new SocialImportModel([], [], [PrivateFeedModel::home()]));

        $result = $this->importer()->import($account, new SocialImportModel([], [], [PrivateFeedModel::home()]));

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
    }

    public function testACollectionOverTheCapIsSkippedNotFailed(): void
    {
        $this->user->setMaxSubscriptions(0);
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));

        $result = $this->importer()->import($account, new SocialImportModel([], [], [PrivateFeedModel::home()]));

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->skippedOverLimit);
    }

    public function testACollectionOverTheCapLeavesTheNextOneToBeCounted(): void
    {
        $this->user->setMaxSubscriptions(1);
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $this->importer()->import($account, new SocialImportModel([], [], [PrivateFeedModel::home()]));

        $result = $this->importer()->import($account, new SocialImportModel([], [], [
            PrivateFeedModel::customFeed(self::NEWS, 'News'),
            PrivateFeedModel::home(),
        ]));

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->skippedOverLimit);
        self::assertSame(1, $result->alreadySubscribed);
    }

    public function testReTickingADroppedCollectionSubscribesItAgain(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $news = PrivateFeedModel::customFeed(self::NEWS, 'News');
        $this->importer()->import($account, new SocialImportModel([], [], [$news]));
        $newsUrl = sprintf('social:bluesky:%d:feed:%s', $account->requireId(), self::NEWS);
        $this->importer()->import($account, new SocialImportModel([], [$this->subscriptionIdOf($newsUrl)]));

        $result = $this->importer()->import($account, new SocialImportModel([], [], [$news]));

        self::assertSame(1, $result->imported);
        $feed = $this->feedAt($newsUrl);
        self::assertNotNull($feed);
        self::assertSame(1, $this->entityManager->getRepository(Subscription::class)->count([
            'user' => $this->user,
            'feed' => $feed,
        ]));
        /** @var KnownSocialCollectionRepository $known */
        $known = $this->entityManager->getRepository(KnownSocialCollection::class);
        self::assertSame(['custom_feed:' . self::NEWS => true], $known->keysOf($account));
    }

    public function testUntickingASubscribedCollectionKeepsItsFavouritesInSavedFromBluesky(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $this->importer()->import($account, new SocialImportModel([], [], [
            PrivateFeedModel::customFeed(self::NEWS, 'News'),
        ]));
        $newsUrl = sprintf('social:bluesky:%d:feed:%s', $account->requireId(), self::NEWS);
        $feed = $this->feedAt($newsUrl);
        self::assertNotNull($feed);
        $guid = 'at://did:plc:carol/app.bsky.feed.post/1';
        $post = SavedPostFixtures::entry($this->entityManager, $feed, $guid, null);
        SavedPostFixtures::state($this->entityManager, $this->user, $post)->markFavorite();
        $this->entityManager->flush();

        $result = $this->importer()->import(
            $account,
            new SocialImportModel([], [$this->subscriptionIdOf($newsUrl)]),
        );

        self::assertSame(1, $result->removed);
        $this->entityManager->clear();
        $saved = $this->feedAt(sprintf('saved:%d:bluesky', $this->user->requireId()));
        self::assertNotNull($saved);
        $copies = $this->entityManager->getRepository(Entry::class)->findBy(['feed' => $saved, 'guid.value' => $guid]);
        self::assertCount(1, $copies);
        /** @var EntryStateRepository $states */
        $states = $this->entityManager->getRepository(EntryState::class);
        self::assertTrue(
            $states->findOneForUserEntry($this->user->requireId(), $copies[0]->requireId())?->isFavorite(),
        );
    }

    #[DataProvider('foreignCollections')]
    public function testACollectionItsNetworkCannotHoldRefusesTheWholeImport(
        SocialNetwork $network,
        PrivateFeedModel $collection,
        string $message,
    ): void {
        $account = $this->storedAccount(SocialNetwork::Bluesky === $network
            ? SocialAccounts::blueskyOf($this->user)
            : SocialAccounts::mastodonOf($this->user));

        try {
            $this->importer()->import(
                $account,
                new SocialImportModel([], [], [PrivateFeedModel::home(), $collection]),
            );
            self::fail('A collection of the wrong shape must refuse the import.');
        } catch (InvalidSocialImportFeedException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertTrue($this->entityManager->isOpen(), 'The refusal must come before the import transaction.');
            self::assertSame(0, $this->entityManager->getRepository(SocialFeed::class)->count());
        }
    }

    /** @return iterable<string, array{SocialNetwork, PrivateFeedModel, string}> */
    public static function foreignCollections(): iterable
    {
        yield 'a mastodon list id on bluesky' => [
            SocialNetwork::Bluesky,
            PrivateFeedModel::userList('42', 'Friends'),
            '42 is no Bluesky list.',
        ];
        yield 'an author feed on mastodon' => [
            SocialNetwork::Mastodon,
            PrivateFeedModel::author('did:plc:rusty', 'Rusty'),
            'did:plc:rusty is no Mastodon profile.',
        ];
        yield 'a custom feed on mastodon' => [
            SocialNetwork::Mastodon,
            PrivateFeedModel::customFeed(self::NEWS, 'News'),
            self::NEWS . ' is no Mastodon custom feed.',
        ];
    }

    public function testAHiddenFollowIsImportedAsAnAuthorFeedAndTheRestAsRss(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $this->hiddenProfiles()->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'));

        $result = $this->importer()->import($account, new SocialImportModel([
            new SocialImportFeedModel('did:plc:rusty', 'Rusty'),
            new SocialImportFeedModel('did:plc:bob', 'Bob'),
        ], []));

        self::assertSame(2, $result->imported);
        self::assertNotNull($this->feedAt('https://bsky.app/profile/did:plc:bob/rss'));
        self::assertNull($this->feedAt('https://bsky.app/profile/did:plc:rusty/rss'));
        $author = $this->feedAt(sprintf('social:bluesky:%d:author:did:plc:rusty', $account->requireId()));
        self::assertSame(SourceFormat::BLUESKY_API, $author?->getSourceFormat());
    }

    public function testAHiddenFollowAlreadyReadThroughTheAccountCountsAsAlreadySubscribed(): void
    {
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $this->hiddenProfiles()->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'));
        $import = new SocialImportModel([new SocialImportFeedModel('did:plc:rusty', 'Rusty')], []);
        $this->importer()->import($account, $import);

        $result = $this->importer()->import($account, $import);

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
    }

    public function testAHiddenFollowOverTheCapIsSkippedWhileTheRssFollowsKeepTheirSlots(): void
    {
        $this->user->setMaxSubscriptions(1);
        $account = $this->storedAccount(SocialAccounts::blueskyOf($this->user));
        $this->hiddenProfiles()->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'));

        $result = $this->importer()->import($account, new SocialImportModel([
            new SocialImportFeedModel('did:plc:rusty', 'Rusty'),
            new SocialImportFeedModel('did:plc:bob', 'Bob'),
        ], []));

        self::assertSame(1, $result->imported);
        self::assertSame(1, $result->skippedOverLimit);
        self::assertNotNull($this->feedAt('https://bsky.app/profile/did:plc:bob/rss'));
        self::assertNull($this->feedAt(sprintf('social:bluesky:%d:author:did:plc:rusty', $account->requireId())));
    }

    private function hiddenProfiles(): StubHiddenProfiles
    {
        $hiddenProfiles = self::getContainer()->get(StubHiddenProfiles::class);
        self::assertInstanceOf(StubHiddenProfiles::class, $hiddenProfiles);

        return $hiddenProfiles;
    }

    private static function follow(string $did, string $avatarUrl): FollowedAccountModel
    {
        return new FollowedAccountModel($did, '@' . $did, null, self::profileFeed($did), $avatarUrl);
    }

    private static function profileFeed(string $did): string
    {
        return 'https://bsky.app/profile/' . $did . '/rss';
    }

    private function subscriptionIdOf(string $feedUrl): int
    {
        $feed = $this->feedAt($feedUrl);
        self::assertNotNull($feed);
        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy([
            'user' => $this->user,
            'feed' => $feed,
        ]);
        self::assertNotNull($subscription);

        return $subscription->requireId();
    }

    private function storedAccount(SocialAccount $account): SocialAccount
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }
}
