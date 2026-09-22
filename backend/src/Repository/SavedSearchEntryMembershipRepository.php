<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedSearchEntry;
use App\Service\Search\Membership\SavedSearchMembershipWriter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The one writer of saved_search_entry (#1116). Reads live on
 * SavedSearchEntryRepository, which projects entries, not memberships.
 *
 * @extends ServiceEntityRepository<SavedSearchEntry>
 */
final class SavedSearchEntryMembershipRepository extends ServiceEntityRepository implements SavedSearchMembershipWriter
{
    /** Rows per INSERT: 3 placeholders each, kept under SQLite's historical 999. */
    private const int INSERT_ROWS = 300;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedSearchEntry::class);
    }

    /**
     * The database itself skips pairs already stored (INSERT IGNORE / OR IGNORE,
     * as EntryStateRepository::ensureRow), so two runs over one chunk cannot
     * collide on the key, and the affected-row count is the pairs added.
     */
    public function insertMissing(array $entryIdsBySavedSearchId, \DateTimeImmutable $matchedAt): int
    {
        $inserted = 0;
        foreach (array_chunk(self::pairs($entryIdsBySavedSearchId), self::INSERT_ROWS) as $rows) {
            $inserted += $this->insertIgnoringStored($rows, $matchedAt);
        }

        return $inserted;
    }

    /**
     * @param array<int, list<int>> $entryIdsBySavedSearchId
     *
     * @return list<array{int, int}> [savedSearchId, entryId]
     */
    private static function pairs(array $entryIdsBySavedSearchId): array
    {
        $pairs = [];
        foreach ($entryIdsBySavedSearchId as $savedSearchId => $entryIds) {
            foreach ($entryIds as $entryId) {
                $pairs[] = [$savedSearchId, $entryId];
            }
        }

        return $pairs;
    }

    /** @param non-empty-list<array{int, int}> $pairs */
    private function insertIgnoringStored(array $pairs, \DateTimeImmutable $matchedAt): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $conflictClause = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'IGNORE' : 'OR IGNORE';

        $parameters = [];
        foreach ($pairs as [$savedSearchId, $entryId]) {
            array_push($parameters, $savedSearchId, $entryId, $matchedAt->format('Y-m-d H:i:s'));
        }

        return (int) $connection->executeStatement(
            \sprintf(
                'INSERT %s INTO saved_search_entry (saved_search_id, entry_id, matched_at) VALUES %s',
                $conflictClause,
                implode(', ', array_fill(0, \count($pairs), '(?, ?, ?)')),
            ),
            $parameters,
        );
    }
}
