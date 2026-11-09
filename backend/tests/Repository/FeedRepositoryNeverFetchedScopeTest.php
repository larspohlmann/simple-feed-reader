<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\User;
use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\SeedsUsers;

final class FeedRepositoryNeverFetchedScopeTest extends DbTestCase
{
    use SeedsUsers;

    private const string NOW = '2026-06-01T00:00:00Z';

    public function testTheScopeSelectsOnlyTheUsersFeedsNoFetchHasStamped(): void
    {
        $owner = $this->user('never-fetched-owner@example.com');
        $fixtures = new NetworkFeedSubscriptions($this->entityManager);
        $fresh = $fixtures->subscribe($owner, 'https://example.com/fresh.xml')->getFeed();
        $fetched = $fixtures->subscribe($owner, NetworkFeedSubscriptions::PLAIN)->getFeed();
        $fetched->recordSuccessfulFetch(new \DateTimeImmutable('2026-05-01T00:00:00Z'), 60);
        $this->entityManager->flush();

        $criteria = $this->criteria($owner);

        self::assertSame([$fresh->requireId()], $this->idsOf($this->feeds()->findDue($criteria, 50)));
        self::assertSame(1, $this->feeds()->countDue($criteria));
    }

    public function testTheScopeSurvivesExcludingFeedsAlreadyHandled(): void
    {
        $owner = $this->user('never-fetched-excluding@example.com');
        $fixtures = new NetworkFeedSubscriptions($this->entityManager);
        $handled = $fixtures->subscribe($owner, 'https://example.com/handled.xml')->getFeed();
        $fetched = $fixtures->subscribe($owner, NetworkFeedSubscriptions::PLAIN)->getFeed();
        $fetched->recordSuccessfulFetch(new \DateTimeImmutable('2026-05-01T00:00:00Z'), 60);
        $this->entityManager->flush();

        $criteria = $this->criteria($owner)->excluding([$handled->requireId()]);

        self::assertSame([], $this->feeds()->findDue($criteria, 50));
    }

    public function testTheScopeNeverReachesAnotherUsersUnfetchedFeed(): void
    {
        $owner = $this->user('never-fetched-self@example.com');
        $stranger = $this->user('never-fetched-stranger@example.com');
        (new NetworkFeedSubscriptions($this->entityManager))->subscribe($stranger, NetworkFeedSubscriptions::PLAIN);

        self::assertSame([], $this->feeds()->findDue($this->criteria($owner), 50));
    }

    private function criteria(User $owner): DueFeedCriteria
    {
        return new DueFeedCriteria(
            new \DateTimeImmutable(self::NOW),
            $owner->requireId(),
            force: true,
            neverFetched: true,
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
