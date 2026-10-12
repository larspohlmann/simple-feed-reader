<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\InvalidSelectionException;
use App\Repository\SubscriptionRepository;
use App\Service\Social\Exception\InvalidSocialImportFeedException;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Social\Model\SocialImportModel;
use App\Service\Social\SocialImporter;
use App\Tests\DbTestCase;
use App\Tests\Support\ProfileFeedSubscriptions;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialImporterTest extends DbTestCase
{
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

    private function blueskyAccount(): SocialAccount
    {
        return SocialAccounts::blueskyOf($this->user);
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

    public function testChosenFollowsBecomeSubscriptionsUnderTheNetworksTagWithTheirTitle(): void
    {
        $result = $this->importer()->import($this->blueskyAccount(), new SocialImportModel([
            new SocialImportFeedModel('https://bsky.app/profile/did:plc:bob/rss', 'Bob'),
            new SocialImportFeedModel('https://bsky.app/profile/did:plc:carol/rss', null),
        ], []));

        self::assertSame(2, $result->imported);
        self::assertSame(0, $result->removed);
        self::assertSame(['Bluesky'], $this->tagNamesOf('https://bsky.app/profile/did:plc:bob/rss'));
        self::assertSame('Bob', $this->feedAt('https://bsky.app/profile/did:plc:bob/rss')?->getTitle());
    }

    public function testAnExistingSubscriptionIsNotRetagged(): void
    {
        $this->subscriptions->subscribeTagged($this->user, 'https://mastodon.example/@bob.rss', 'Friends');

        $result = $this->importer()->import(SocialAccounts::mastodonOf($this->user), new SocialImportModel([
            new SocialImportFeedModel('https://mastodon.example/@bob.rss', 'Bob'),
        ], []));

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
        self::assertSame(['Friends'], $this->tagNamesOf('https://mastodon.example/@bob.rss'));
    }

    public function testAFeedThatIsNoProfileFeedOfTheAccountsNetworkRefusesTheWholeImport(): void
    {
        $keptId = $this->subscribedId($this->user, 'https://bsky.app/profile/did:plc:gone/rss');

        try {
            $this->importer()->import($this->blueskyAccount(), new SocialImportModel([
                new SocialImportFeedModel('https://bsky.app/profile/did:plc:bob/rss', 'Bob'),
                new SocialImportFeedModel('https://mastodon.example/@bob.rss', 'Bob'),
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

        $result = $this->importer()->import($this->blueskyAccount(), new SocialImportModel([], [$goneId]));

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

        $this->expectException(InvalidSelectionException::class);

        $this->importer()->import($this->blueskyAccount(), new SocialImportModel([], [$theirsId]));
    }
}
