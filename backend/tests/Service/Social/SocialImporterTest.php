<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Exception\InvalidSelectionException;
use App\Repository\SubscriptionRepository;
use App\Service\Social\Exception\InvalidSocialImportFeedException;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Social\Model\SocialImportModel;
use App\Service\Social\SocialImporter;
use App\Tests\DbTestCase;
use App\Tests\Support\ProfileFeedSubscriptions;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialImporterTest extends DbTestCase
{
    private const string NEWS = 'at://did:plc:bob/app.bsky.feed.generator/news';

    private User $user;

    private ProfileFeedSubscriptions $subscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('social-importer@example.test');
        $this->subscriptions = new ProfileFeedSubscriptions($this->entityManager);
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
        $result = $this->importer()->import(SocialAccounts::blueskyOf($this->user), new SocialImportModel([
            new SocialImportFeedModel('did:plc:bob', 'Bob'),
            new SocialImportFeedModel('did:plc:carol', null),
        ], []));

        self::assertSame(2, $result->imported);
        self::assertSame(0, $result->removed);
        self::assertSame([], $this->tagNamesOf('https://bsky.app/profile/did:plc:bob/rss'));
        self::assertSame('Bob', $this->feedAt('https://bsky.app/profile/did:plc:bob/rss')?->getTitle());
    }

    public function testAnExistingSubscriptionIsNotRetagged(): void
    {
        $this->subscriptions->subscribeTagged($this->user, 'https://mastodon.example/@bob.rss', 'Friends');

        $result = $this->importer()->import(SocialAccounts::mastodonOf($this->user), new SocialImportModel([
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
            $this->importer()->import(SocialAccounts::blueskyOf($this->user), new SocialImportModel([
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

        $account = SocialAccounts::blueskyOf($this->user);

        $result = $this->importer()->import($account, new SocialImportModel([], [$goneId]));

        self::assertSame(1, $result->removed);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Subscription::class, $goneId));
    }

    public function testAnotherUsersSubscriptionCannotBeRemoved(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stranger = (new UserFactory($this->entityManager, $hasher))->create('social-importer-stranger@example.test');
        $theirsId = $this->subscribedId($stranger, 'https://bsky.app/profile/did:plc:theirs/rss');

        try {
            $account = SocialAccounts::blueskyOf($this->user);
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

        $result = $this->importer()->import(SocialAccounts::blueskyOf($this->user), new SocialImportModel([
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
        yield 'a custom feed on mastodon' => [
            SocialNetwork::Mastodon,
            PrivateFeedModel::customFeed(self::NEWS, 'News'),
            self::NEWS . ' is no Mastodon custom feed.',
        ];
    }

    private function storedAccount(SocialAccount $account): SocialAccount
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }
}
