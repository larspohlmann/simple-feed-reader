<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\BulkInsert;
use App\Entity\SavedSearchEntry;
use App\Service\Search\Membership\SavedSearchMembershipWriter\SavedSearchMembershipWriterInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The one writer of saved_search_entry. Reads live on SavedSearchEntryRepository, which projects entries, not
 * memberships.
 *
 * @extends ServiceEntityRepository<SavedSearchEntry>
 */
final class SavedSearchEntryMembershipRepository extends ServiceEntityRepository implements
    SavedSearchMembershipWriterInterface
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
        $connection = $this->getEntityManager()->getConnection();

        return BulkInsert::rows(
            $connection,
            \sprintf(
                'INSERT %s INTO saved_search_entry (saved_search_id, entry_id, matched_at)',
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            self::rows($entryIdsBySavedSearchId, $matchedAt->format('Y-m-d H:i:s')),
            self::INSERT_ROWS,
        );
    }

    /**
     * @param array<int, list<int>> $entryIdsBySavedSearchId
     *
     * @return list<array{int, int, string}> [savedSearchId, entryId, matchedAt]
     */
    private static function rows(array $entryIdsBySavedSearchId, string $matchedAt): array
    {
        $rows = [];
        foreach ($entryIdsBySavedSearchId as $savedSearchId => $entryIds) {
            foreach ($entryIds as $entryId) {
                $rows[] = [$savedSearchId, $entryId, $matchedAt];
            }
        }

        return $rows;
    }
}
