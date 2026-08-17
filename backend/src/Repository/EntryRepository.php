<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entry>
 */
class EntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    /**
     * @param list<string> $guidHashes
     *
     * @return list<string> the subset of hashes that already exist for this feed
     */
    public function findExistingGuidHashes(Feed $feed, array $guidHashes): array
    {
        if ($guidHashes === []) {
            return [];
        }

        /** @var list<string> $existing */
        $existing = $this->createQueryBuilder('e')
            ->select('e.guidHash')
            ->andWhere('e.feed = :feed')
            ->andWhere('e.guidHash IN (:hashes)')
            ->setParameter('feed', $feed)
            ->setParameter('hashes', $guidHashes)
            ->getQuery()
            ->getSingleColumnResult();

        return $existing;
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<int> the subset of ids that still exist — a caller holding
     *                   ids from an earlier checkpoint uses this to drop the
     *                   ones pruned since
     */
    public function findExistingIds(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<int> $existing */
        $existing = $this->createQueryBuilder('e')
            ->select('e.id')
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->getQuery()
            ->getSingleColumnResult();

        return $existing;
    }

    /**
     * The whole table, walked in ascending-id slices for app:search:reindex.
     * Id keyset (`id > :lastId`), never OFFSET: OFFSET re-scans and discards
     * every prior row on each call, which turns a full-table walk quadratic
     * once the table holds tens of thousands of rows. Feed is fetched eagerly
     * so EntryIndexer::toIndexedEntries() costs no extra query per row.
     *
     * @return list<Entry>
     */
    public function entriesAfterId(int $lastId, int $limit): array
    {
        /** @var list<Entry> $entries */
        $entries = $this->createQueryBuilder('e')
            ->addSelect('f')
            ->join('e.feed', 'f')
            ->andWhere('e.id > :lastId')
            ->setParameter('lastId', $lastId)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }

    /**
     * One feed's entries in ascending-id slices — the backup export's keyset
     * walk. No feed join: the caller already knows the feed and only needs
     * its own scalar fields on each entry.
     *
     * @return list<Entry>
     */
    public function forFeedAfterId(int $feedId, int $afterId, int $limit): array
    {
        /** @var list<Entry> $entries */
        $entries = $this->createQueryBuilder('e')
            ->andWhere('e.feed = :feed')
            ->andWhere('e.id > :afterId')
            ->setParameter('feed', $feedId)
            ->setParameter('afterId', $afterId)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $entries;
    }

    /**
     * The feed's existing entries for the given guid hashes, indexed by hash —
     * lets a re-parse match items back to their persisted rows.
     *
     * @param list<string> $guidHashes
     *
     * @return array<string, Entry>
     */
    public function findByFeedIndexedByGuidHash(Feed $feed, array $guidHashes): array
    {
        if ($guidHashes === []) {
            return [];
        }

        /** @var list<Entry> $rows */
        $rows = $this->createQueryBuilder('e')
            ->andWhere('e.feed = :feed')
            ->andWhere('e.guidHash IN (:hashes)')
            ->setParameter('feed', $feed)
            ->setParameter('hashes', $guidHashes)
            ->getQuery()
            ->getResult();

        $byHash = [];
        foreach ($rows as $entry) {
            $byHash[$entry->getGuidHash()] = $entry;
        }

        return $byHash;
    }

    /**
     * The feed's whole guid hash ⇒ entry id map, as scalars. The restore uses
     * it twice: to drop the file's entries the feed already holds, and to
     * attach the file's entry states to rows whose ids the source instance
     * never knew. Ids and hashes only — hydrating the entities would put a
     * feed's entire back catalogue in memory for a two-column lookup.
     *
     * @return array<string, int>
     */
    public function guidHashToIdMapForFeed(int $feedId): array
    {
        /** @var list<array{guidHash: string, id: int}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.guidHash AS guidHash', 'e.id AS id')
            ->andWhere('e.feed = :feed')
            ->setParameter('feed', $feedId)
            ->getQuery()
            ->getResult();

        $idsByHash = [];
        foreach ($rows as $row) {
            $idsByHash[$row['guidHash']] = $row['id'];
        }

        return $idsByHash;
    }
}
