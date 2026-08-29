<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryState;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\RecommendationCursor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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
     * The cursor arrives decoded, from the pager that owns the rule for a
     * malformed one; everything else the page was asked for is on the query.
     *
     * @return list<RecommendationFeedRow>
     */
    public function listForYou(ForYouFeedQuery $query, ?RecommendationCursor $cursor): array
    {
        $qb = $this->rowQueryBuilder($query->userId())
            ->orderBy('r.id', 'DESC')
            ->addOrderBy('i.position', 'ASC')
            ->setMaxResults($query->limit);

        if ($query->unreadOnly) {
            $this->applyUnread($qb);
        }

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
        return $this->applyForYouCriteria($this->createQueryBuilder('i')->addSelect('e'), $userId)
            ->leftJoin('e.feed', 'f')->addSelect('f')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->addSelect('s.id AS subscriptionId')
            ->addSelect('s.customTitle AS customTitle')
            ->addSelect('f.title AS feedTitle')
            ->addSelect('f.url AS feedUrl')
            ->addSelect('es.isHidden AS esHidden')
            ->addSelect('es.isFavorite AS esFavorite')
            ->addSelect('es.isKept AS esKept')
            ->addSelect('es.isViewed AS esViewed')
            ->addSelect('s.markedReadUntil AS markedReadUntil')
            ->addSelect('r.completedAt AS runCompletedAt');
    }

    /**
     * The unread picks in this user's for-you feed, from runs that had already
     * finished at `$until`.
     *
     * The cut-off is the RUN's completion time, not the entry's date: the feed
     * is ranked, not dated, so a run finishing while the reader looks at the
     * list can add an old entry at the top. Bounding by entry date would mark
     * that new pick read; bounding by the run leaves it, which is what the
     * reader means by "mark all read" — everything I could see.
     *
     * @return list<int>
     */
    public function unreadEntryIdsForYou(int $userId, \DateTimeImmutable $until): array
    {
        $qb = $this->applyForYouCriteria($this->createQueryBuilder('i')->select('e.id AS id'), $userId)
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->andWhere('r.completedAt <= :until')
            ->setParameter('until', $until);

        $this->applyUnread($qb);

        /** @var list<array{id: int}> $rows */
        $rows = $qb->getQuery()->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function countForYou(int $userId): int
    {
        $count = $this->applyForYouCriteria($this->createQueryBuilder('i')->select('COUNT(i.id)'), $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Two-step (select ids, then delete) rather than a DELETE with a
     * subquery: portable across both suite dialects and trivially testable,
     * same shape as RecommendationRunLogRepository::deleteForUser().
     */
    public function deleteForUser(User $user): void
    {
        /** @var list<int> $ids */
        $ids = array_column(
            $this->createQueryBuilder('i')
                ->select('i.id AS id')
                ->join('i.run', 'r')
                ->where('r.user = :user')
                ->setParameter('user', $user)
                ->getQuery()
                ->getArrayResult(),
            'id',
        );

        if ([] === $ids) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\RecommendationItem i WHERE i.id IN (:ids)',
        )->setParameter('ids', $ids)->execute();
    }

    /** The for-you feed's row set: completed runs of this user, entries still
     *  subscribed to a feed with for-you recommendations enabled, deduped to
     *  their newest run. Shared by the pager and the count so the sidebar
     *  number can never disagree with the list. */
    private function applyForYouCriteria(QueryBuilder $qb, int $userId): QueryBuilder
    {
        return $qb
            ->join('i.run', 'r')
            ->join('i.entry', 'e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->andWhere('r.user = :user')
            ->andWhere('r.status = :completed')
            ->andWhere('s.includeInForYou = true')
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

    /**
     * "Unread" means here exactly what it means in the main list — the shared
     * `UnreadDql` predicate, which folds the subscription's read watermark in
     * with the entry's own state. Needs the `es`, `s` and `e` aliases the row
     * projection already joins.
     */
    private function applyUnread(QueryBuilder $qb): void
    {
        $qb->andWhere(UnreadDql::predicate())->setParameter('notHidden', false, Types::BOOLEAN);
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
        $esHidden = $row['esHidden'];
        $markedReadUntil = $row['markedReadUntil'];
        $runCompletedAt = $row['runCompletedAt'];

        $listRow = new EntryListRow(
            entry: $entry,
            subscriptionId: self::toInt($row['subscriptionId']),
            subscriptionTitle: $this->rowTitle($row),
            isHidden: EffectiveReadState::isHidden(
                $esHidden === null ? null : (bool) $esHidden,
                $markedReadUntil instanceof \DateTimeInterface ? $markedReadUntil : null,
                $entry->getEffectiveDate(),
            ),
            isFavorite: (bool) ($row['esFavorite'] ?? false),
            isKept: (bool) ($row['esKept'] ?? false),
            isViewed: (bool) ($row['esViewed'] ?? false),
            // The for-you feed ranks by score, never by view time, so its rows
            // carry no viewedAt — nothing reads it on this path.
            viewedAt: null,
            markedReadUntil: $markedReadUntil instanceof \DateTimeImmutable ? $markedReadUntil : null,
        );

        return new RecommendationFeedRow(
            row: $listRow,
            reason: $item->getReason(),
            runId: $item->getRun()->getId() ?? 0,
            position: $item->getPosition(),
            score: $item->getScore(),
            runGeneratedAt: $runCompletedAt instanceof \DateTimeImmutable ? $runCompletedAt : null,
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
