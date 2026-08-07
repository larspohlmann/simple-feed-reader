<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryState;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Http\RecommendationCursor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecommendationItem>
 */
final class RecommendationItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecommendationItem::class);
    }

    /**
     * The for-you feed: items of the caller's completed runs, newest run
     * first and position ascending within a run, keyset-paginated. An entry
     * recommended in several runs shows only in its newest occurrence.
     * Unsubscribed feeds drop out, exactly like the main entry list.
     *
     * @return list<RecommendationFeedRow>
     */
    public function listForYou(int $userId, ?RecommendationCursor $cursor, int $limit): array
    {
        $limit = max(1, min($limit, EntryQuery::MAX_LIMIT));

        $qb = $this->rowQueryBuilder($userId)
            ->orderBy('r.id', 'DESC')
            ->addOrderBy('i.position', 'ASC')
            ->setMaxResults($limit);

        $this->applyCursor($qb, $cursor);

        /** @var list<array<array-key, mixed>> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(fn (array $row): RecommendationFeedRow => $this->hydrateRow($row), $rows);
    }

    /**
     * The shared projection: same fields as EntryRepository::rowQueryBuilder
     * so EntryListRow hydrates identically, plus the run/position/reason the
     * main list has no concept of. Only completed runs of the caller.
     */
    private function rowQueryBuilder(int $userId): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->addSelect('e')
            ->join('i.run', 'r')
            ->join('i.entry', 'e')
            ->leftJoin('e.feed', 'f')->addSelect('f')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->addSelect('s.id AS subscriptionId')
            ->addSelect('s.customTitle AS customTitle')
            ->addSelect('f.title AS feedTitle')
            ->addSelect('f.url AS feedUrl')
            ->addSelect('es.isRead AS esRead')
            ->addSelect('es.isFavorite AS esFavorite')
            ->addSelect('es.isKept AS esKept')
            ->addSelect('es.isViewed AS esViewed')
            ->addSelect('s.markedReadUntil AS markedReadUntil')
            ->andWhere('r.user = :user')
            ->andWhere('r.status = :completed')
            ->andWhere($this->notDedupedByNewerRunDql())
            ->setParameter('user', $userId)
            ->setParameter('completed', RecommendationRun::STATUS_COMPLETED);
    }

    /**
     * An entry recommended by several completed runs of this user shows only
     * in its newest run: excluded here if a completed run with a higher id
     * also recommended the same entry.
     */
    private function notDedupedByNewerRunDql(): string
    {
        return sprintf(
            'NOT EXISTS (
                SELECT i2.id FROM %s i2 JOIN i2.run r2
                WHERE i2.entry = i.entry
                AND r2.user = :user
                AND r2.status = :completed
                AND r2.id > r.id
            )',
            RecommendationItem::class,
        );
    }

    private function applyCursor(QueryBuilder $qb, ?RecommendationCursor $cursor): void
    {
        if ($cursor === null) {
            return;
        }

        $qb->andWhere('(r.id < :curRun OR (r.id = :curRun AND i.position > :curPos))')
            ->setParameter('curRun', $cursor->runId)
            ->setParameter('curPos', $cursor->position);
    }

    /**
     * @param array<array-key, mixed> $row a mixed DQL result: [0 => RecommendationItem, 1 => Entry, scalars...]
     */
    private function hydrateRow(array $row): RecommendationFeedRow
    {
        /** @var RecommendationItem $item */
        $item = $row[0];
        $entry = $item->getEntry();
        $esRead = $row['esRead'];
        $markedReadUntil = $row['markedReadUntil'];

        $listRow = new EntryListRow(
            entry: $entry,
            subscriptionId: self::toInt($row['subscriptionId']),
            subscriptionTitle: $this->rowTitle($row),
            isRead: EffectiveReadState::isRead(
                $esRead === null ? null : (bool) $esRead,
                $markedReadUntil instanceof \DateTimeInterface ? $markedReadUntil : null,
                $entry->getEffectiveDate(),
            ),
            isFavorite: (bool) ($row['esFavorite'] ?? false),
            isKept: (bool) ($row['esKept'] ?? false),
            isViewed: (bool) ($row['esViewed'] ?? false),
            markedReadUntil: $markedReadUntil instanceof \DateTimeImmutable ? $markedReadUntil : null,
        );

        return new RecommendationFeedRow(
            row: $listRow,
            reason: $item->getReason(),
            runId: $item->getRun()->getId() ?? 0,
            position: $item->getPosition(),
        );
    }

    private static function toInt(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function rowTitle(array $row): string
    {
        $customTitle = $row['customTitle'];
        $feedTitle = $row['feedTitle'];
        $feedUrl = $row['feedUrl'];

        return SubscriptionDisplayTitle::from(
            \is_string($customTitle) ? $customTitle : null,
            \is_string($feedTitle) ? $feedTitle : null,
            \is_string($feedUrl) ? $feedUrl : '',
        );
    }
}
