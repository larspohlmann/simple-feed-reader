<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\FeedGroup;
use App\Enum\SourceFormat;
use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\SeedsUsers;

final class FeedRepositoryFeedGroupScopeTest extends DbTestCase
{
    use SeedsUsers;

    private const string NOW = '2026-06-01T00:00:00Z';

    public function testTheTagsGroupSelectsEveryTaggedFeedOfTheUserSocialIncluded(): void
    {
        [$owner, $taggedPlain, $taggedProfile] = $this->seedOwnerWithFourSubscriptions();

        $criteria = $this->criteria($owner, FeedGroup::Tags);

        self::assertSame(
            [$taggedPlain->requireId(), $taggedProfile->requireId()],
            $this->idsOf($this->feeds()->findDue($criteria, 50)),
        );
        self::assertSame(2, $this->feeds()->countDue($criteria));
    }

    public function testTheFeedsGroupSelectsOnlyUntaggedFeedsOnNoNetwork(): void
    {
        [$owner, , , $untaggedPlain] = $this->seedOwnerWithFourSubscriptions();

        $criteria = $this->criteria($owner, FeedGroup::Feeds);

        self::assertSame([$untaggedPlain->requireId()], $this->idsOf($this->feeds()->findDue($criteria, 50)));
        self::assertSame(1, $this->feeds()->countDue($criteria));
    }

    public function testAGroupNeverReachesAFeedTagOnlyAnotherUserCarries(): void
    {
        $owner = $this->user('feed-group-self@example.com');
        $stranger = $this->user('feed-group-stranger@example.com');
        $feed = new Feed(NetworkFeedSubscriptions::PLAIN);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($owner, $feed, new \DateTimeImmutable(self::NOW)));
        $strangerSubscription = new Subscription($stranger, $feed, new \DateTimeImmutable(self::NOW));
        $this->entityManager->persist($strangerSubscription);
        $this->tag($stranger, $strangerSubscription, 'news');

        $criteria = $this->criteria($owner, FeedGroup::Tags);

        self::assertSame([], $this->feeds()->findDue($criteria, 50));
        self::assertSame(0, $this->feeds()->countDue($criteria));
    }

    /**
     * @return array{User, Feed, Feed, Feed, Feed}
     */
    private function seedOwnerWithFourSubscriptions(): array
    {
        $owner = $this->user('feed-group-owner@example.com');
        $fixtures = new NetworkFeedSubscriptions($this->entityManager);
        $taggedPlain = $fixtures->subscribe($owner, NetworkFeedSubscriptions::PLAIN);
        $taggedProfile = $fixtures->subscribe($owner, NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $untaggedPlain = $fixtures->subscribe($owner, 'https://example.com/untagged.xml');
        $untaggedHome = $fixtures->subscribe(
            $owner,
            NetworkFeedSubscriptions::blueskyHome(1),
            SourceFormat::BLUESKY_API,
        );
        $this->tag($owner, $taggedPlain, 'news');
        $this->tag($owner, $taggedProfile, 'tech');

        return [
            $owner,
            $taggedPlain->getFeed(),
            $taggedProfile->getFeed(),
            $untaggedPlain->getFeed(),
            $untaggedHome->getFeed(),
        ];
    }

    private function tag(User $owner, Subscription $subscription, string $name): void
    {
        $tag = new Tag($owner, $name);
        $this->entityManager->persist($tag);
        $subscription->addTag($tag);
        $this->entityManager->flush();
    }

    private function criteria(User $owner, FeedGroup $group): DueFeedCriteria
    {
        return new DueFeedCriteria(
            new \DateTimeImmutable(self::NOW),
            $owner->requireId(),
            force: true,
            feedGroup: $group,
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

    private function feeds(): FeedRepository
    {
        $repository = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);

        return $repository;
    }
}
