<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\SocialLike;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Entry> */
final class EntryTwinRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    /** @return array<int, int> the target feed's entry id, by the source feed's entry id of the same post */
    public function sameGuidEntryIds(int $userId, int $sourceFeedId, int $targetFeedId): array
    {
        /** @var list<array{sourceId: int, targetId: int}> $rows */
        $rows = $this->createQueryBuilder('sourceEntry')
            ->select('sourceEntry.id AS sourceId', 'targetEntry.id AS targetId')
            ->join(
                Entry::class,
                'targetEntry',
                Join::ON,
                'targetEntry.feed = :targetFeed AND targetEntry.guid.hash = sourceEntry.guid.hash',
            )
            ->andWhere('sourceEntry.feed = :sourceFeed')
            ->andWhere(sprintf(
                '(EXISTS (SELECT IDENTITY(userState.entry) FROM %s userState'
                . ' WHERE userState.entry = sourceEntry AND userState.user = :user)'
                . ' OR EXISTS (SELECT userLike.id FROM %s userLike'
                . ' WHERE userLike.entry = sourceEntry AND userLike.user = :user))',
                EntryState::class,
                SocialLike::class,
            ))
            ->setParameter('targetFeed', $targetFeedId)
            ->setParameter('sourceFeed', $sourceFeedId)
            ->setParameter('user', $userId)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'targetId', 'sourceId');
    }
}
