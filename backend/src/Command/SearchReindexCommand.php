<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EntryRepository;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\SearchIndexWriter;
use App\Service\Search\SearchEngineCapability;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the search index from the database. Two jobs, both repair:
 * EntryIndexer's ingest-time writes swallow SearchEngineUnavailableException (an
 * outage must never break a feed refresh), so this recovers what they missed;
 * and it is what an operator runs once after pointing an install at
 * MEILISEARCH_URL, since pre-existing entries stay unindexed until then.
 *
 * Failure handling is the INVERSE of EntrySearchWithFallback's: no engine means a
 * search falls back to the database (not an error), but a reindex with no engine
 * has nothing to rebuild and no fallback, so it exits non-zero -- and an engine
 * erroring partway through must not report success (see execute()).
 *
 * Walks `entry` in ascending-id batches (EntryRepository::entriesAfterId(), never
 * OFFSET), clearing the entity manager between them to bound memory over tens of
 * thousands of rows.
 *
 * Meilisearch writes are asynchronous -- 202 now, indexed later (measured,
 * `docs/meilisearch-wire-format.md`) -- so SearchIndexWriter returns void rather
 * than polling; a reindex-only poll would duplicate the one class that knows the
 * wire format. This command reports only that every batch was accepted, not that
 * the engine has caught up (see the closing `note()`).
 */
#[AsCommand(
    name: 'app:search:reindex',
    description: 'Rebuild the search index from the database',
)]
final class SearchReindexCommand extends Command
{
    /**
     * Chosen to match the DELETE_CHUNK_SIZE convention EntryPruner /
     * OrphanedFeedReclaimer already use for bulk work over `entry` — large
     * enough that a full table walk stays a handful of round-trips to the
     * engine, small enough that one batch's entities plus their joined feed
     * are a rounding error against a normal PHP memory limit.
     */
    private const int BATCH_SIZE = 500;

    public function __construct(
        private readonly SearchIndexWriter $writer,
        private readonly EntryRepository $entries,
        private readonly EntityManagerInterface $em,
        private readonly SearchEngineCapability $capability,
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
        } catch (SearchEngineUnavailableException $e) {
            $io->error(\sprintf('The search engine did not answer during the rebuild: %s', $e->getMessage()));

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
            $lastId = (int) $batch[array_key_last($batch)]->getId();
            $io->writeln(\sprintf('  %d indexed', $indexed));

            // Keeps the run's memory bounded over a full table: without this,
            // every batch's entities (and their joined feeds) stay in the
            // identity map for the rest of the process.
            $this->em->clear();
        }
    }
}
