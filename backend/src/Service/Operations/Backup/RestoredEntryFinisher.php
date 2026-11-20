<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup;

use App\Entity\Entry;
use App\Service\Foundation\CanonicalItem\CanonicalItemLinker;
use App\Service\Library\Reading\CopyReadStatePropagator;
use App\Service\Library\Search\EntryIndexer;
use App\Service\Operations\Backup\Exception\BackupLoadFailedException;
use App\Service\Operations\Backup\RestoreEntries\RestoreEntriesInterface;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Completes the entries a restore wrote natively: links them to their canonical items, lets each inherit a copy's
 * read and hands them to the search index, in the keyset batches app:search:reindex walks in, so a restore of any
 * size hydrates one batch at a time.
 */
final readonly class RestoredEntryFinisher
{
    private const int BATCH = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RestoreEntriesInterface $entries,
        private CanonicalItemLinker $linker,
        private EntryIndexer $indexer,
        private CopyReadStatePropagator $copyReadStates,
    ) {
    }

    /** @param list<int> $entryIds the entries a restore created */
    public function finishCreated(array $entryIds): void
    {
        if ([] === $entryIds) {
            return;
        }

        $createdEntryIds = array_flip($entryIds);
        $lastId = min($entryIds) - 1;
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
            $this->copyReadStates->inherit($created);
            $this->indexer->index($created);
            $batchSize = \count($batch);
            $this->entityManager->clear();
        } while (self::BATCH === $batchSize);
    }

    /** @param list<Entry> $created */
    private function linkToItems(array $created): void
    {
        try {
            $this->linker->linkClaimed($created);
            $this->entityManager->flush();
        } catch (DbalException $exception) {
            throw BackupLoadFailedException::duringEntries($exception);
        }
    }
}
