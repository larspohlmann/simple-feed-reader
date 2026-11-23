<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PostCommunity;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Repository\LemmyPostRepository;
use App\Service\Social\Lemmy\LemmyHosts;
use App\Tests\DbTestCase;
use App\Tests\Support\CanonicalItems;
use App\Tests\Support\SocialAccounts;

final class LemmyHostsTest extends DbTestCase
{
    private const string CREATED_AT = '2026-11-23 12:00:00';

    public function testCollectsAccountFeedAndCommunityHostsOnce(): void
    {
        $reader = $this->persistUser('reader@example.com');
        $account = SocialAccounts::lemmyOf($reader, 'Home.Example');
        $communityFeed = $this->subscribe($reader, 'https://lemmy.world/feeds/c/rust.xml', SocialNetwork::Lemmy);
        $this->persistPostIn($communityFeed, 'one', 'https://programming.dev/c/php');
        $this->persistPostIn($communityFeed, 'two', 'https://lemmy.world/c/rust');

        self::assertSame(
            ['home.example', 'lemmy.world', 'programming.dev'],
            $this->hosts()->collectFor($reader->requireId(), [$account], [$communityFeed]),
        );
    }

    public function testSortsTheHostsAndDropsAddressesWithoutOne(): void
    {
        $reader = $this->persistUser('reader@example.com');
        $account = SocialAccounts::lemmyOf($reader, 'zeta.example');
        $feed = $this->subscribe($reader, 'https://alpha.example/feeds/c/rust.xml', SocialNetwork::Lemmy);
        $this->persistPostIn($feed, 'four', 'not an address');

        self::assertSame(
            ['alpha.example', 'zeta.example'],
            $this->hosts()->collectFor($reader->requireId(), [$account], [$feed]),
        );
    }

    public function testNamesNoHostsWithoutALemmyAccount(): void
    {
        $reader = $this->persistUser('reader@example.com');
        $feed = $this->subscribe($reader, 'https://lemmy.world/feeds/c/rust.xml', SocialNetwork::Lemmy);
        $this->persistPostIn($feed, 'five', 'https://programming.dev/c/php');

        self::assertSame(
            [],
            $this->hosts()->collectFor($reader->requireId(), [SocialAccounts::mastodonOf($reader)], [$feed]),
        );
    }

    public function testIgnoresOtherUsersCommunities(): void
    {
        $reader = $this->persistUser('reader@example.com');
        $stranger = $this->persistUser('stranger@example.com');
        $strangersFeed = $this->subscribe($stranger, 'https://feddit.org/feeds/c/news.xml', SocialNetwork::Lemmy);
        $this->persistPostIn($strangersFeed, 'three', 'https://sh.itjust.works/c/news');

        self::assertSame(
            ['home.example'],
            $this->hosts()->collectFor($reader->requireId(), [SocialAccounts::lemmyOf($reader, 'home.example')], []),
        );
    }

    public function testIgnoresNonLemmyFeeds(): void
    {
        $reader = $this->persistUser('reader@example.com');
        $subscriptions = [
            $this->subscribe($reader, 'https://mastodon.social/@alice.rss', SocialNetwork::Mastodon),
            $this->subscribe($reader, 'https://example.com/feed.xml', null),
        ];

        self::assertSame(
            ['home.example'],
            $this->hosts()->collectFor(
                $reader->requireId(),
                [SocialAccounts::lemmyOf($reader, 'home.example')],
                $subscriptions,
            ),
        );
    }

    private function hosts(): LemmyHosts
    {
        $hosts = self::getContainer()->get(LemmyHosts::class);
        self::assertInstanceOf(LemmyHosts::class, $hosts);

        return $hosts;
    }

    private function persistUser(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable(self::CREATED_AT));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function subscribe(User $user, string $feedUrl, ?SocialNetwork $network): Subscription
    {
        $feed = new Feed($feedUrl);
        $feed->classifyAs($network);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable(self::CREATED_AT));
        $this->entityManager->persist($feed);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    private function persistPostIn(Subscription $subscription, string $name, string $communityUrl): void
    {
        $address = 'https://lemmy.test/post/' . $name;
        $item = CanonicalItems::buildItem(CanonicalItemKind::Lemmy, $address);
        $this->entityManager->persist($item);
        $entry = new Entry(
            $subscription->getFeed(),
            $address,
            null,
            $name,
            new \DateTimeImmutable(self::CREATED_AT),
            new \DateTimeImmutable(self::CREATED_AT),
        );
        $entry->linkTo($item);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $posts = self::getContainer()->get(LemmyPostRepository::class);
        self::assertInstanceOf(LemmyPostRepository::class, $posts);
        $posts->storeCommunities([
            $item->requireId() => PostCommunity::fromIdentityAndUrl($name . '@community', $communityUrl),
        ]);
    }
}
