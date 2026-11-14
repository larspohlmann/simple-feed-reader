<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\User;
use App\Repository\DueFeedCriteria;
use App\Service\Ingestion\Refresh\Model\RefreshTarget\NeverFetchedTargetModel;
use App\Tests\DbTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use App\Tests\Support\QueriesDueFeeds;
use App\Tests\Support\SeedsUsers;

final class FeedRepositoryNeverFetchedScopeTest extends DbTestCase
{
    use QueriesDueFeeds;
    use SeedsUsers;

    private const string NOW = '2026-06-01T00:00:00Z';

    public function testTheScopeSelectsOnlyTheUsersFeedsNoFetchHasStamped(): void
    {
        $owner = $this->user('never-fetched-owner@example.com');
        [$fresh] = $this->seedFreshAndFetched($owner);

        $criteria = $this->criteria($owner);

        self::assertSame([$fresh->requireId()], $this->idsOf($this->feeds()->findDue($criteria, 50)));
        self::assertSame(1, $this->feeds()->countDue($criteria));
    }

    public function testTheScopeSurvivesExcludingFeedsAlreadyHandled(): void
    {
        $owner = $this->user('never-fetched-excluding@example.com');
        [$handled] = $this->seedFreshAndFetched($owner);

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

    /**
     * @return array{Feed, Feed}
     */
    private function seedFreshAndFetched(User $owner): array
    {
        $fixtures = new NetworkFeedSubscriptions($this->entityManager);
        $fresh = $fixtures->subscribe($owner, 'https://example.com/fresh.xml')->getFeed();
        $fetched = $fixtures->subscribe($owner, NetworkFeedSubscriptions::PLAIN)->getFeed();
        $fetched->recordSuccessfulFetch(new \DateTimeImmutable('2026-05-01T00:00:00Z'), 60);
        $this->entityManager->flush();

        return [$fresh, $fetched];
    }

    private function criteria(User $owner): DueFeedCriteria
    {
        return $this->dueCriteriaFor($owner, new NeverFetchedTargetModel(), new \DateTimeImmutable(self::NOW));
    }
}
