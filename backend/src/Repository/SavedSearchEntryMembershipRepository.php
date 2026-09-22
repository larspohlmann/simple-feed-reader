<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedSearchEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
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
     * Inserts the (search, entry) pairs that do not exist yet and answers how
     * many it added. A re-run over the same chunk therefore adds nothing,
     * which is what makes the sweep's per-chunk transaction restartable.
     *
     * @param list<int> $entryIds
     */
    public function insertMissing(int $savedSearchId, array $entryIds, \DateTimeImmutable $matchedAt): int
    {
        if ($entryIds === []) {
            return 0;
        }

        $missing = array_values(array_diff($entryIds, $this->existingEntryIds($savedSearchId, $entryIds)));
        foreach (array_chunk($missing, self::INSERT_ROWS) as $rows) {
            $this->insertRows($savedSearchId, $rows, $matchedAt);
        }

        return \count($missing);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<int>
     */
    private function existingEntryIds(int $savedSearchId, array $entryIds): array
    {
        /** @var list<int|string> $existing */
        $existing = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT entry_id FROM saved_search_entry WHERE saved_search_id = ? AND entry_id IN (?)',
            [$savedSearchId, $entryIds],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );

        return array_map(intval(...), $existing);
    }

    /** @param list<int> $entryIds */
    private function insertRows(int $savedSearchId, array $entryIds, \DateTimeImmutable $matchedAt): void
    {
        $parameters = [];
        foreach ($entryIds as $entryId) {
            array_push($parameters, $savedSearchId, $entryId, $matchedAt->format('Y-m-d H:i:s'));
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO saved_search_entry (saved_search_id, entry_id, matched_at) VALUES '
            . implode(', ', array_fill(0, \count($entryIds), '(?, ?, ?)')),
            $parameters,
        );
    }
}
