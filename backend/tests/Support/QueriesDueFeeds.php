<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Entity\User;
use App\Repository\DueFeedCriteria;
use App\Repository\FeedRepository;
use App\Repository\RefreshTarget;

trait QueriesDueFeeds
{
    private function dueCriteriaFor(User $owner, RefreshTarget $target, \DateTimeImmutable $now): DueFeedCriteria
    {
        return new DueFeedCriteria($now, $target, $owner->requireId(), force: true);
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
