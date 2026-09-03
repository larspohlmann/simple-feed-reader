<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Entry;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\IndexedEntry;
use App\Service\Search\Index\SearchIndexWriter;
use App\Service\Text\PlainText;
use Psr\Log\LoggerInterface;

/**
 * Keeps the search index in step with what the database already holds: called
 * once a caller's flush has given every new Entry its id, and once
 * EntryPruner has decided which ids a bulk delete removed.
 *
 * Indexing is a side effect of storing (or discarding) an entry, never a
 * condition of it succeeding: a slow or down search engine must cost a
 * refresh nothing beyond staler results, so every method here swallows
 * SearchEngineUnavailableException and logs it rather than propagating —
 * `app:search:reindex` is the repair path, do not make the exception escape
 * again. An unconfigured engine raises no exception at all: MeilisearchIndex
 * makes every write a no-op (#816), so an install without search stays silent.
 *
 * NOT `final readonly class`: $configured is a memoised flag mutated after
 * construction (see index()). This service is a process-lifetime singleton and
 * RefreshRunner calls index() once per feed (up to 50 per sweep), so without
 * memoising it would PATCH identical, idempotent settings up to 50 times per
 * sweep for no gain. Every other collaborator stays constructor-promoted
 * `readonly`; only $configured needs to change after construction.
 */
final class EntryIndexer
{
    private bool $configured = false;

    public function __construct(
        private readonly SearchIndexWriter $index,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Entry> $entries newly flushed entries — every one must
     *        already have an id, which only holds true after the caller's
     *        flush
     */
    public function index(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        try {
            $this->configureOnce();
            $this->index->upsert(self::toIndexedEntries($entries));
        } catch (SearchEngineUnavailableException $e) {
            $this->logger->error('Failed to index entries', ['exception' => $e]);
        }
    }

    /**
     * Idempotent and cheap on Meilisearch's side (a PATCH of the same settings) —
     * this is what makes a freshly enabled container usable without a separate
     * provisioning step, so it must run at least once. Memoised to at most once
     * per process; a failed attempt leaves $configured false so the next
     * index() call retries.
     *
     * @throws SearchEngineUnavailableException
     */
    private function configureOnce(): void
    {
        if ($this->configured) {
            return;
        }

        $this->index->configure();
        $this->configured = true;
    }

    /**
     * The Entry-to-IndexedEntry mapping alone: no engine call, nothing swallowed.
     * `app:search:reindex` needs the exact same mapping this class uses at ingest
     * time (a second, drifting mapping is the bug DRY prevents), but must let
     * SearchEngineUnavailableException reach its caller rather than disappear
     * into a log line — ruling out reusing index() itself. This pure entry
     * point is the shared piece, with no opinion on a failed write.
     *
     * @param list<Entry> $entries
     *
     * @return list<IndexedEntry>
     */
    public static function toIndexedEntries(array $entries): array
    {
        return array_map(self::toIndexedEntry(...), $entries);
    }

    /**
     * @param list<int> $entryIds ids captured before the deleting bulk DQL
     *        ran — EntryPruner deletes outside the ORM's identity map, so
     *        there is no entity left afterwards to read an id from
     */
    public function forget(array $entryIds): void
    {
        if ($entryIds === []) {
            return;
        }

        try {
            $this->index->forget($entryIds);
        } catch (SearchEngineUnavailableException $e) {
            $this->logger->error('Failed to remove entries from the index', ['exception' => $e]);
        }
    }

    /**
     * A feed's title can change on any later refresh
     * (EntryIngestor::updateFeedMetadata), but an already-indexed entry keeps the
     * feedTitle given here until a full app:search:reindex — this class doesn't
     * propagate renames onto entries indexed under the old title. Accepted: a
     * renamed feed is rare next to the ingest volume per-entry propagation would cost.
     */
    private static function toIndexedEntry(Entry $entry): IndexedEntry
    {
        return new IndexedEntry(
            id: (int) $entry->getId(),
            feedId: (int) $entry->getFeed()->getId(),
            title: $entry->getTitle(),
            summary: $entry->getSummary(),
            content: PlainText::fromHtmlBlocks($entry->getContentHtml()),
            feedTitle: $entry->getFeed()->getTitle(),
            effectiveDate: $entry->getEffectiveDate(),
        );
    }
}
