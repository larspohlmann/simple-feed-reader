<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EntryRepository;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\SearchIndexWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the search index from the database. Two jobs in one, both repair:
 * EntryIndexer's ingest-time writes deliberately swallow
 * SearchEngineUnavailableException (a search outage must never break a feed
 * refresh), so this is the path that makes anything they missed durable again;
 * and it is what an operator runs once after pointing an existing install at
 * MEILISEARCH_URL for the first time, since every pre-existing entry is
 * unindexed until this runs.
 *
 * Failure handling is the INVERSE of EntrySearchWithFallback's. A search with
 * no engine configured has the database to fall back to, so it is not an
 * error. A reindex with no engine configured has nothing to rebuild and no
 * fallback, so it exits non-zero. Likewise an engine that answers with an
 * error partway through must not be reported as a success — see execute().
 *
 * Walks the whole `entry` table in ascending-id batches
 * (EntryRepository::entriesAfterId(), never OFFSET) and clears the entity
 * manager between them, so a table of tens of thousands of rows runs in
 * bounded memory rather than growing the identity map for the whole run.
 *
 * Every write Meilisearch accepts is asynchronous — it answers 202 and
 * indexes afterwards (measured against the running engine, see
 * `.superpowers/sdd/2026-08-16-432-meilisearch-search/task-2-report.md` and
 * its `wire-format-addendum.md`) — and SearchIndexWriter's methods return
 * void precisely because MeilisearchIndex does not poll the task queue on
 * anyone's behalf; growing that adapter a reindex-only polling path would
 * duplicate the one class that already knows Meilisearch's wire format
 * behind a second, harder-to-trust code path. This command therefore reports
 * only that every batch was accepted, and says so in its own summary rather
 * than implying the engine has already caught up — see the closing `note()`.
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
        private readonly string $engineUrl,
        private readonly int $batchSize = self::BATCH_SIZE,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('' === $this->engineUrl) {
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
