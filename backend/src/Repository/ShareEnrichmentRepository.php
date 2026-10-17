<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ShareEnrichment> */
final class ShareEnrichmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareEnrichment::class);
    }

    public function hasPendingForFeed(Feed $feed, ShareEnrichmentKind $kind): bool
    {
        return $this->createQueryBuilder('pending')
            ->select('pending.id')
            ->innerJoin('pending.entry', 'queuedEntry')
            ->andWhere('queuedEntry.feed = :feed')
            ->andWhere('pending.kind = :kind')
            ->setParameter('feed', $feed)
            ->setParameter('kind', $kind)
            ->setMaxResults(1)
            ->getQuery()
            ->getScalarResult() !== [];
    }

    /** @return list<ShareEnrichment> those of the feed's claimable rows queued before the give-up window, oldest first */
    public function findClaimableGivenUpForFeed(
        Feed $feed,
        ShareEnrichmentKind $kind,
        \DateTimeImmutable $now,
        int $limit,
    ): array {
        return self::rowsOf($this->claimableForFeed($feed, $kind, $now)
            ->andWhere('pending.queuedAt < :giveUpCutoff')
            ->setParameter('giveUpCutoff', ShareEnrichment::giveUpCutoff($now), Types::DATETIME_IMMUTABLE)
            ->setMaxResults($limit));
    }

    /** @return list<ShareEnrichment> those of the feed's claimable rows queued within the give-up window, oldest first */
    public function findClaimableLiveForFeed(
        Feed $feed,
        ShareEnrichmentKind $kind,
        \DateTimeImmutable $now,
        int $limit,
    ): array {
        return self::rowsOf($this->claimableForFeed($feed, $kind, $now)
            ->andWhere('pending.queuedAt >= :giveUpCutoff')
            ->setParameter('giveUpCutoff', ShareEnrichment::giveUpCutoff($now), Types::DATETIME_IMMUTABLE)
            ->setMaxResults($limit));
    }

    /** @return list<ShareEnrichment> the oldest link-card rows no worker holds, of every feed */
    public function findClaimableLinkCards(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<ShareEnrichment> $rows */
        $rows = $this->claimable($now)
            ->andWhere('pending.kind = :kind')
            ->setParameter('kind', ShareEnrichmentKind::LinkCards)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<ShareEnrichment> the rows of those still pending that no worker holds, oldest first
     */
    public function findClaimableFor(array $entries, \DateTimeImmutable $now): array
    {
        if ($entries === []) {
            return [];
        }

        /** @var list<ShareEnrichment> $rows */
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
     * @param list<ShareEnrichment> $rows
     *
     * @return list<ShareEnrichment> the rows this call now holds
     */
    public function claim(array $rows, \DateTimeImmutable $now): array
    {
        $query = $this->getEntityManager()->createQuery(sprintf(
            'UPDATE %s pending SET pending.claimedAt = :now WHERE pending.id = :id AND pending.doneAt IS NULL'
            . ' AND (pending.claimedAt IS NULL OR pending.claimedAt < :leaseStart)',
            ShareEnrichment::class,
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
     * @param list<ShareEnrichment> $rows
     */
    public function release(array $rows, \DateTimeImmutable $claimedAt): void
    {
        $this->updateHeld($rows, $claimedAt, 'pending.claimedAt = NULL');
    }

    /**
     * The attempt is dated by its claim.
     *
     * @param list<ShareEnrichment> $rows
     */
    public function markDone(array $rows, \DateTimeImmutable $claimedAt): void
    {
        $this->updateHeld($rows, $claimedAt, 'pending.doneAt = :claimedAt, pending.claimedAt = NULL');
    }

    /**
     * @param list<ShareEnrichment> $rows
     *
     * @return list<ShareEnrichment> those still held by the claim made at $claimedAt
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
            static fn (ShareEnrichment $row): bool => isset($held[$row->requireId()]),
        ));
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

    private function claimableForFeed(Feed $feed, ShareEnrichmentKind $kind, \DateTimeImmutable $now): QueryBuilder
    {
        return $this->claimable($now)
            ->andWhere('queuedEntry.feed = :feed')
            ->andWhere('pending.kind = :kind')
            ->setParameter('feed', $feed)
            ->setParameter('kind', $kind);
    }

    /** @return list<ShareEnrichment> */
    private static function rowsOf(QueryBuilder $query): array
    {
        /** @var list<ShareEnrichment> $rows */
        $rows = $query->getQuery()->getResult();

        return $rows;
    }

    private function claimable(\DateTimeImmutable $now): QueryBuilder
    {
        return $this->createQueryBuilder('pending')
            ->innerJoin('pending.entry', 'queuedEntry')
            ->innerJoin('queuedEntry.feed', 'queuedFeed')
            ->addSelect('queuedEntry', 'queuedFeed')
            ->andWhere('pending.doneAt IS NULL')
            ->andWhere('pending.claimedAt IS NULL OR pending.claimedAt < :leaseStart')
            ->setParameter('leaseStart', self::leaseStart($now), Types::DATETIME_IMMUTABLE)
            ->orderBy('pending.queuedAt', 'ASC')
            ->addOrderBy('pending.id', 'ASC');
    }

    /**
     * @param list<ShareEnrichment> $rows
     *
     * @return list<int>
     */
    private static function idsOf(array $rows): array
    {
        return array_map(static fn (ShareEnrichment $row): int => $row->requireId(), $rows);
    }

    /** @param list<ShareEnrichment> $rows */
    private function updateHeld(array $rows, \DateTimeImmutable $claimedAt, string $assignments): void
    {
        if ($rows === []) {
            return;
        }

        $this->getEntityManager()->createQuery(sprintf(
            'UPDATE %s pending SET %s WHERE pending.id IN (:ids) AND pending.claimedAt = :claimedAt',
            ShareEnrichment::class,
            $assignments,
        ))
            ->setParameter('ids', self::idsOf($rows))
            ->setParameter('claimedAt', $claimedAt, Types::DATETIME_IMMUTABLE)
            ->execute();
    }

    private static function leaseStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->sub(new \DateInterval(ShareEnrichment::CLAIM_LEASE));
    }
}
