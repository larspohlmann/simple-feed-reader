<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup;

use App\Entity\Entry;
use App\Service\Foundation\CanonicalItem\CanonicalItemLinker;
use App\Service\Library\Search\EntryIndexer;
use App\Service\Operations\Backup\Exception\BackupLoadFailedException;
use App\Service\Operations\Backup\RestoreEntries\RestoreEntriesInterface;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Links the entries a restore wrote natively to their canonical items and hands them to the search index, in the
 * same keyset batches app:search:reindex walks in, so a restore of any size hydrates one batch at a time.
 */
final readonly class RestoredEntryFinisher
{
    private const int BATCH = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RestoreEntriesInterface $entries,
        private CanonicalItemLinker $linker,
        private EntryIndexer $indexer,
    ) {
    }

    /** @param array<int, true> $createdEntryIds the ids the restore created, as a set */
    public function finishCreated(array $createdEntryIds): void
    {
        if ([] === $createdEntryIds) {
            return;
        }

        $lastId = min(array_keys($createdEntryIds)) - 1;
        do {
            $batch = $this->entries->entriesAfterId($lastId, self::BATCH);
            $created = [];
            foreach ($batch as $entry) {
                $lastId = $entry->requireId();
                if (isset($createdEntryIds[$lastId])) {
                    $created[] = $entry;
                }
            }
            $this->linkToItems($created);
            $this->indexer->index($created);
            $batchSize = \count($batch);
            $this->entityManager->clear();
        } while (self::BATCH === $batchSize);
    }

    /** @param list<Entry> $created */
    private function linkToItems(array $created): void
    {
        $this->linker->linkClaimed($created);
        try {
            $this->entityManager->flush();
        } catch (DbalException $exception) {
            throw BackupLoadFailedException::duringEntries($exception);
        }
    }
}
