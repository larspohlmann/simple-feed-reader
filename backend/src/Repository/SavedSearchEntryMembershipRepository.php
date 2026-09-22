<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedSearchEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The one writer of saved_search_entry (#1116). Reads live on
 * SavedSearchEntryRepository, which projects entries, not memberships.
 *
 * @extends ServiceEntityRepository<SavedSearchEntry>
 */
class SavedSearchEntryMembershipRepository extends ServiceEntityRepository
{
    /** Rows per INSERT: 3 placeholders each, kept under SQLite's historical 999. */
    private const int INSERT_ROWS = 300;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedSearchEntry::class);
    }

    /**
     * Inserts the (search, entry) pairs that do not exist yet — one existence
     * read and one INSERT for the whole map — and answers how many it added,
     * so a re-run over the same chunk adds nothing and the sweep's per-chunk
     * transaction is restartable.
     *
     * @param array<int, list<int>> $entryIdsBySavedSearchId
     */
    public function insertMissing(array $entryIdsBySavedSearchId, \DateTimeImmutable $matchedAt): int
    {
        $wanted = self::pairs($entryIdsBySavedSearchId);
        if ($wanted === []) {
            return 0;
        }

        $missing = array_values(array_diff_key($wanted, $this->existingPairs($wanted)));
        foreach (array_chunk($missing, self::INSERT_ROWS) as $rows) {
            $this->insertRows($rows, $matchedAt);
        }

        return \count($missing);
    }

    /**
     * @param array<int, list<int>> $entryIdsBySavedSearchId
     *
     * @return array<string, array{int, int}> "search:entry" => [savedSearchId, entryId]
     */
    private static function pairs(array $entryIdsBySavedSearchId): array
    {
        $pairs = [];
        foreach ($entryIdsBySavedSearchId as $savedSearchId => $entryIds) {
            foreach ($entryIds as $entryId) {
                $pairs[$savedSearchId . ':' . $entryId] = [$savedSearchId, $entryId];
            }
        }

        return $pairs;
    }

    /**
     * @param non-empty-array<string, array{int, int}> $wanted
     *
     * @return array<string, true> the "search:entry" keys already stored
     */
    private function existingPairs(array $wanted): array
    {
        /** @var list<array{searchId: int|string, entryId: int|string}> $rows */
        $rows = $this->createQueryBuilder('sse')
            ->select('IDENTITY(sse.savedSearch) AS searchId', 'IDENTITY(sse.entry) AS entryId')
            ->andWhere('sse.savedSearch IN (:searchIds)')
            ->andWhere('sse.entry IN (:entryIds)')
            ->setParameter('searchIds', array_values(array_unique(array_column($wanted, 0))))
            ->setParameter('entryIds', array_values(array_unique(array_column($wanted, 1))))
            ->getQuery()
            ->getScalarResult();

        $existing = [];
        foreach ($rows as $row) {
            $existing[(int) $row['searchId'] . ':' . (int) $row['entryId']] = true;
        }

        return $existing;
    }

    /** @param list<array{int, int}> $pairs */
    private function insertRows(array $pairs, \DateTimeImmutable $matchedAt): void
    {
        $parameters = [];
        foreach ($pairs as [$savedSearchId, $entryId]) {
            array_push($parameters, $savedSearchId, $entryId, $matchedAt->format('Y-m-d H:i:s'));
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO saved_search_entry (saved_search_id, entry_id, matched_at) VALUES '
            . implode(', ', array_fill(0, \count($pairs), '(?, ?, ?)')),
            $parameters,
        );
    }
}
