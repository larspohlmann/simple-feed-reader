<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Repository\NetworkScope;
use App\Tests\DbTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\SeedsUsers;

final class FeedRepositoryNetworkScopeTest extends DbTestCase
{
    use SeedsUsers;

    private const string NOW = '2026-06-01T00:00:00Z';

    public function testANetworkScopeSelectsOnlyThatNetworksFeedsOfTheUserEvenWhenHiddenFromAllItems(): void
    {
        $owner = $this->user('network-scope-owner@example.com');
        $home = $this->fixtures()->subscribe(
            $owner,
            NetworkFeedSubscriptions::blueskyHome(1),
            SourceFormat::BLUESKY_API,
        )->getFeed();
        $hiddenSubscription = $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $hiddenSubscription->setIncludeInAllItems(false);
        $this->entityManager->flush();
        $profile = $hiddenSubscription->getFeed();
        $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::MASTODON_PROFILE);
        $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::PLAIN);

        $criteria = $this->criteria($owner, NetworkScope::whole(SocialNetwork::Bluesky));

        self::assertSame(
            [$home->requireId(), $profile->requireId()],
            $this->idsOf($this->feeds()->findDue($criteria, 50)),
        );
        self::assertSame(2, $this->feeds()->countDue($criteria));
    }

    public function testANetworkWithoutFeedsSelectsNothing(): void
    {
        $owner = $this->user('network-scope-empty@example.com');
        $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::PLAIN);

        $criteria = $this->criteria($owner, NetworkScope::whole(SocialNetwork::Mastodon));

        self::assertSame([], $this->feeds()->findDue($criteria, 50));
        self::assertSame(0, $this->feeds()->countDue($criteria));
    }

    public function testAProfileScopeSelectsOnlyTheNetworksProfileFeeds(): void
    {
        $owner = $this->user('network-scope-profiles@example.com');
        $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::blueskyHome(1), SourceFormat::BLUESKY_API);
        $profile = $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::BLUESKY_PROFILE)->getFeed();

        $criteria = $this->criteria($owner, NetworkScope::profiles(SocialNetwork::Bluesky));

        self::assertSame([$profile->requireId()], $this->idsOf($this->feeds()->findDue($criteria, 50)));
        self::assertSame(1, $this->feeds()->countDue($criteria));
    }

    public function testANetworkScopeNeverReachesAFeedTheUserDoesNotSubscribeTo(): void
    {
        $owner = $this->user('network-scope-self@example.com');
        $stranger = $this->user('network-scope-stranger@example.com');
        $this->fixtures()->subscribe($stranger, 'https://bsky.app/profile/bob.bsky.social/rss');

        $criteria = $this->criteria($owner, NetworkScope::whole(SocialNetwork::Bluesky));

        self::assertSame([], $this->feeds()->findDue($criteria, 50));
    }

    private function criteria(User $owner, NetworkScope $network): DueFeedCriteria
    {
        return new DueFeedCriteria(
            new \DateTimeImmutable(self::NOW),
            $owner->requireId(),
            force: true,
            network: $network,
        );
    }

    /**
     * @param list<Feed> $feeds
     *
     * @return list<int>
     */
    private function idsOf(array $feeds): array
    {
        return array_map(static fn (Feed $feed): int => $feed->requireId(), $feeds);
    }

    private function fixtures(): NetworkFeedSubscriptions
    {
        return new NetworkFeedSubscriptions($this->entityManager);
    }

    private function feeds(): FeedRepository
    {
        $repository = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);

        return $repository;
    }
}
