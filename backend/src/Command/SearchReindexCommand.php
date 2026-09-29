<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EntryRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Service\Search\SearchEngineCapability;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the search index from the database: the repair for writes EntryIndexer swallowed during an outage, and the
 * first run after setting MEILISEARCH_URL. Unlike a search, a missing or failing engine exits non-zero.
 */
#[AsCommand(
    name: 'app:search:reindex',
    description: 'Rebuild the search index from the database',
)]
final class SearchReindexCommand extends Command
{
    /** DELETE_CHUNK_SIZE's value (EntryPruner, OrphanedFeedReclaimer): few engine round-trips, little memory each. */
    private const int BATCH_SIZE = 500;

    public function __construct(
        private readonly SearchIndexWriterInterface $writer,
        private readonly EntryRepository $entries,
        private readonly EntityManagerInterface $entityManager,
        private readonly SearchEngineCapability $capability,
        private readonly SavedSearchRepository $savedSearches,
        private readonly int $batchSize = self::BATCH_SIZE,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->capability->isConfigured()) {
            $io->error('No search engine is configured (MEILISEARCH_URL is empty); there is nothing to rebuild.');

            return Command::FAILURE;
        }

        try {
            return $this->rebuild($io);
        } catch (SearchEngineUnavailableException $exception) {
            $io->error(\sprintf('The search engine did not answer during the rebuild: %s', $exception->getMessage()));

            return Command::FAILURE;
        }
    }

    /**
     * @throws SearchEngineUnavailableException
     */
    private function rebuild(SymfonyStyle $io): int
    {
        // Idempotent settings PATCH, then a clear: a reindex must also remove
        // documents whose entries are gone since the last write, not just add
        // what is missing.
        $this->writer->configure();
        $this->writer->clear();

        $indexed = $this->indexEveryBatch($io);

        $io->success(\sprintf('Reindexed %d entries.', $indexed));
        $io->note(\sprintf(
            '%d saved-search membership marks reset; the sweep re-matches them against the rebuilt index. '
            . 'If the engine is still indexing when it runs, run app:saved-search:rematch once it has settled.',
            $this->savedSearches->resetAllMarks(),
        ));
        $io->note(
            'Meilisearch indexes asynchronously: this only confirms every batch was '
            . 'accepted, not that the engine has finished indexing it. GET /indexes/entries/stats '
            . 'is known to lag behind real writes; verify with GET /indexes/entries/documents '
            . 'once the engine has had time to settle.',
        );

        return Command::SUCCESS;
    }

    /**
     * @throws SearchEngineUnavailableException
     */
    private function indexEveryBatch(SymfonyStyle $io): int
    {
        $indexed = 0;
        $lastId = 0;

        while (true) {
            $batch = $this->entries->entriesAfterId($lastId, $this->batchSize);
            if ($batch === []) {
                return $indexed;
            }

            $this->writer->upsert(EntryIndexer::toIndexedEntries($batch));

            $indexed += \count($batch);
            $lastId = $batch[array_key_last($batch)]->requireId();
            $io->writeln(\sprintf('  %d indexed', $indexed));

            // Keeps the run's memory bounded over a full table: without this,
            // every batch's entities (and their joined feeds) stay in the
            // identity map for the rest of the process.
            $this->entityManager->clear();
        }
    }
}
