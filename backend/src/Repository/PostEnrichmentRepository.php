<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PostEnrichment;
use App\Enum\PostEnrichmentKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PostEnrichment> */
final class PostEnrichmentRepository extends ServiceEntityRepository
{
    private const string NAIVE_UTC = 'Y-m-d H:i:s';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostEnrichment::class);
    }

    public function deleteAppViewQueuedBefore(Feed $feed, \DateTimeImmutable $cutoff): void
    {
        $this->getEntityManager()->createQuery(sprintf(
            'DELETE FROM %s pending WHERE pending.kind = :kind AND pending.queuedAt < :cutoff'
            . ' AND IDENTITY(pending.entry) IN'
            . ' (SELECT queuedEntry.id FROM %s queuedEntry WHERE queuedEntry.feed = :feed)',
            PostEnrichment::class,
            Entry::class,
        ))
            ->setParameter('kind', PostEnrichmentKind::AppView)
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->setParameter('feed', $feed)
            ->execute();
    }

    public function hasAppViewPendingForFeed(Feed $feed): bool
    {
        return $this->createQueryBuilder('pending')
            ->select('pending.id')
            ->innerJoin('pending.entry', 'queuedEntry')
            ->andWhere('queuedEntry.feed = :feed')
            ->andWhere('pending.kind = :kind')
            ->setParameter('feed', $feed)
            ->setParameter('kind', PostEnrichmentKind::AppView)
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult() !== [];
    }

    /** @return list<PostEnrichment> the feed's AppView rows no worker holds, oldest first */
    public function findClaimableAppViewForFeed(Feed $feed, \DateTimeImmutable $now, int $limit): array
    {
        /** @var list<PostEnrichment> $rows */
        $rows = $this->claimable($now)
            ->andWhere('queuedEntry.feed = :feed')
            ->andWhere('pending.kind = :kind')
            ->setParameter('feed', $feed)
            ->setParameter('kind', PostEnrichmentKind::AppView)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<PostEnrichment> the rows of those still pending that no worker holds, oldest first
     */
    public function findClaimableFor(array $entries, \DateTimeImmutable $now): array
    {
        if ($entries === []) {
            return [];
        }

        /** @var list<PostEnrichment> $rows */
        $rows = $this->claimable($now)
            ->andWhere('pending.entry IN (:entries)')
            ->setParameter('entries', $entries)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Each row is claimed by its own conditional UPDATE, so of two callers racing for one row exactly one sees it
     * change.
     *
     * @param list<PostEnrichment> $rows
     *
     * @return list<PostEnrichment> the rows this call now holds
     */
    public function claim(array $rows, \DateTimeImmutable $now): array
    {
        $query = $this->getEntityManager()->createQuery(sprintf(
            'UPDATE %s pending SET pending.claimedAt = :now WHERE pending.id = :id AND pending.doneAt IS NULL'
            . ' AND (pending.claimedAt IS NULL OR pending.claimedAt < :leaseStart)',
            PostEnrichment::class,
        ))
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('leaseStart', self::leaseStart($now), Types::DATETIME_IMMUTABLE);

        $claimed = [];
        foreach ($rows as $row) {
            if ($query->setParameter('id', $row->requireId())->execute() === 1) {
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    /**
     * Writes after a claim name the claim's time, so a holder whose lease another worker took over changes nothing.
     *
     * @param list<PostEnrichment> $rows
     */
    public function release(array $rows, \DateTimeImmutable $claimedAt): void
    {
        $this->updateHeld($rows, $claimedAt, 'pending.claimedAt = NULL');
    }

    /**
     * The attempt is dated by its claim; doneAt is assigned first, as MySQL assigns left to right.
     *
     * @param list<PostEnrichment> $rows
     */
    public function markDone(array $rows, \DateTimeImmutable $claimedAt): void
    {
        $this->updateHeld($rows, $claimedAt, 'pending.doneAt = pending.claimedAt, pending.claimedAt = NULL');
    }

    /**
     * @param list<PostEnrichment> $rows
     *
     * @return list<PostEnrichment> those still held by the claim made at $claimedAt
     */
    public function stillHeld(array $rows, \DateTimeImmutable $claimedAt): array
    {
        if ($rows === []) {
            return [];
        }

        /** @var list<int|string> $heldIds */
        $heldIds = $this->createQueryBuilder('pending')
            ->select('pending.id')
            ->andWhere('pending.id IN (:ids)')
            ->andWhere('pending.claimedAt = :claimedAt')
            ->setParameter('ids', self::idsOf($rows))
            ->setParameter('claimedAt', $claimedAt, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleColumnResult();
        $held = array_flip(array_map(intval(...), $heldIds));

        return array_values(array_filter(
            $rows,
            static fn (PostEnrichment $row): bool => isset($held[$row->requireId()]),
        ));
    }

    public function isQueuedOrCarded(Entry $entry): bool
    {
        $connection = $this->getEntityManager()->getConnection();

        return $connection->fetchOne(
            'SELECT 1 FROM entry WHERE entry.id = ?'
            . ' AND (EXISTS (SELECT 1 FROM post_enrichment WHERE post_enrichment.entry_id = entry.id)'
            . ' OR EXISTS (SELECT 1 FROM link_card WHERE link_card.entry_id = entry.id))',
            [$entry->requireId()],
        ) !== false;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<int> those among them whose embed or cards are still to come
     */
    public function pendingEntryIdsAmong(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<int|string> $pending */
        $pending = $this->createQueryBuilder('pending')
            ->select('IDENTITY(pending.entry)')
            ->andWhere('pending.entry IN (:entryIds)')
            ->andWhere('pending.doneAt IS NULL')
            ->setParameter('entryIds', $entryIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $pending);
    }

    /**
     * Queues a link-card row unless the entry has one already or holds cards: the database skips a row already
     * stored, so two opens of one post cannot collide on the key.
     *
     * @return bool whether this call queued it
     */
    public function queueLinkCardsUnlessKnown(Entry $entry, \DateTimeImmutable $queuedAt): bool
    {
        $connection = $this->getEntityManager()->getConnection();

        return $connection->executeStatement(
            sprintf(
                'INSERT %s INTO post_enrichment (entry_id, kind, queued_at) SELECT entry.id, ?, ? FROM entry'
                . ' WHERE entry.id = ? AND NOT EXISTS (SELECT 1 FROM link_card WHERE link_card.entry_id = entry.id)',
                DatabasePlatform::isMySql($connection) ? 'IGNORE' : 'OR IGNORE',
            ),
            [PostEnrichmentKind::LinkCards->value, $queuedAt->format(self::NAIVE_UTC), $entry->requireId()],
        ) === 1;
    }

    private function claimable(\DateTimeImmutable $now): QueryBuilder
    {
        return $this->createQueryBuilder('pending')
            ->innerJoin('pending.entry', 'queuedEntry')
            ->addSelect('queuedEntry')
            ->andWhere('pending.doneAt IS NULL')
            ->andWhere('pending.claimedAt IS NULL OR pending.claimedAt < :leaseStart')
            ->setParameter('leaseStart', self::leaseStart($now), Types::DATETIME_IMMUTABLE)
            ->orderBy('pending.queuedAt', 'ASC')
            ->addOrderBy('pending.id', 'ASC');
    }

    /**
     * @param list<PostEnrichment> $rows
     *
     * @return list<int>
     */
    private static function idsOf(array $rows): array
    {
        return array_map(static fn (PostEnrichment $row): int => $row->requireId(), $rows);
    }

    /** @param list<PostEnrichment> $rows */
    private function updateHeld(array $rows, \DateTimeImmutable $claimedAt, string $assignments): void
    {
        if ($rows === []) {
            return;
        }

        $this->getEntityManager()->createQuery(sprintf(
            'UPDATE %s pending SET %s WHERE pending.id IN (:ids) AND pending.claimedAt = :claimedAt',
            PostEnrichment::class,
            $assignments,
        ))
            ->setParameter('ids', self::idsOf($rows))
            ->setParameter('claimedAt', $claimedAt, Types::DATETIME_IMMUTABLE)
            ->execute();
    }

    private static function leaseStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->sub(new \DateInterval(PostEnrichment::CLAIM_LEASE));
    }
}
