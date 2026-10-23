<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\FeedGroup;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Repository\NetworkScope;
use App\Repository\SubscriptionScopeRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\SeedsUsers;

final class SubscriptionScopeRepositoryTest extends DbTestCase
{
    use SeedsUsers;

    public function testANetworkHoldsItsPrivateAndProfileFeedsExcludedOnesIncluded(): void
    {
        $user = $this->user('network-owner@example.com');
        $home = $this->fixtures()->subscribe(
            $user,
            NetworkFeedSubscriptions::blueskyHome(1),
            SourceFormat::BLUESKY_API,
        );
        $home->setIncludeInAllItems(false);
        $profile = $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE);
        $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::PLAIN);
        $this->entityManager->flush();

        self::assertSame(
            [$home->requireId(), $profile->requireId()],
            $this->idsOnNetwork($user, NetworkScope::whole(SocialNetwork::Bluesky)),
        );
    }

    public function testAProfileScopeHoldsOnlyTheNetworksProfileFeeds(): void
    {
        $user = $this->user('network-profiles-owner@example.com');
        $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::blueskyHome(1), SourceFormat::BLUESKY_API);
        $profile = $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE);

        self::assertSame(
            [$profile->requireId()],
            $this->idsOnNetwork($user, NetworkScope::profiles(SocialNetwork::Bluesky)),
        );
    }

    public function testAnotherUsersFeedsOfTheNetworkAreNeverIncluded(): void
    {
        $user = $this->user('network-self@example.com');
        $own = $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE);
        $stranger = $this->user('network-stranger@example.com');
        $this->fixtures()->subscribe($stranger, 'https://fosstodon.org/@bob.rss');

        self::assertSame(
            [$own->requireId()],
            $this->idsOnNetwork($user, NetworkScope::whole(SocialNetwork::Mastodon)),
        );
    }

    public function testASavedFeedIsInTheWholeNetworkAndNotAmongItsProfiles(): void
    {
        $user = $this->user('network-saved-owner@example.com');
        $saved = $this->fixtures()->subscribe(
            $user,
            sprintf('saved:%d:bluesky', $user->requireId()),
            SourceFormat::SAVED,
        );

        self::assertSame(
            [$saved->requireId()],
            $this->idsOnNetwork($user, NetworkScope::whole(SocialNetwork::Bluesky)),
        );
        self::assertSame([], $this->idsOnNetwork($user, NetworkScope::profiles(SocialNetwork::Bluesky)));
    }

    public function testANetworkWithoutFeedsHoldsNone(): void
    {
        $user = $this->user('network-empty@example.com');
        $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::PLAIN);

        self::assertSame([], $this->idsOnNetwork($user, NetworkScope::whole(SocialNetwork::Bluesky)));
    }

    /** TagEditor::delete() detaches through it: a missed subscription would keep wearing the deleted tag. */
    public function testFindForUserByTagIdReturnsEveryCarryingSubscription(): void
    {
        $user = $this->user('find-by-tag-many@example.com');
        $tag = new Tag($user, 'Shared');
        $this->entityManager->persist($tag);
        $first = $this->fixtures()->subscribe($user, 'https://a.example/feed.xml');
        $first->addTag($tag, 0);
        $second = $this->fixtures()->subscribe($user, 'https://b.example/feed.xml');
        $second->addTag($tag, 1);
        $this->entityManager->flush();

        self::assertSame(
            [$first->requireId(), $second->requireId()],
            $this->idsOf($this->subscriptions()->findForUserByTagId($user->requireId(), $tag->requireId())),
        );
    }

    public function testAFeedGroupHoldsExactlyItsSidebarSection(): void
    {
        $user = $this->user('feed-group-owner@example.com');
        $tag = new Tag($user, 'Group');
        $this->entityManager->persist($tag);
        $tagged = $this->fixtures()->subscribe($user, 'https://tagged.example/feed.xml');
        $tagged->addTag($tag, 0);
        $untagged = $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::PLAIN);
        $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::blueskyHome(1), SourceFormat::BLUESKY_API);
        $this->entityManager->flush();

        $userId = $user->requireId();
        self::assertSame(
            [$tagged->requireId()],
            $this->idsOf($this->subscriptions()->findForUserInFeedGroup($userId, FeedGroup::Tags)),
        );
        self::assertSame(
            [$untagged->requireId()],
            $this->idsOf($this->subscriptions()->findForUserInFeedGroup($userId, FeedGroup::Feeds)),
        );
        self::assertSame(
            [$tagged->requireId()],
            $this->subscriptions()->idsForUserInFeedGroup($userId, FeedGroup::Tags),
        );
        self::assertSame(
            [$untagged->requireId()],
            $this->subscriptions()->idsForUserInFeedGroup($userId, FeedGroup::Feeds),
        );
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return list<int>
     */
    private function idsOf(array $subscriptions): array
    {
        return array_map(static fn (Subscription $subscription): int => $subscription->requireId(), $subscriptions);
    }

    private function fixtures(): NetworkFeedSubscriptions
    {
        return new NetworkFeedSubscriptions($this->entityManager);
    }

    private function subscriptions(): SubscriptionScopeRepository
    {
        $repository = self::getContainer()->get(SubscriptionScopeRepository::class);
        self::assertInstanceOf(SubscriptionScopeRepository::class, $repository);

        return $repository;
    }

    /** @return list<int> */
    private function idsOnNetwork(User $user, NetworkScope $networkScope): array
    {
        return $this->idsOf($this->subscriptions()->findForUserOnNetwork($user->requireId(), $networkScope));
    }
}
