<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Entry;
use App\Service\Text\PlainText;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\IndexedEntry;
use App\Service\Search\Index\SearchIndexWriter;
use Psr\Log\LoggerInterface;

/**
 * Keeps the search index in step with what the database already holds: called
 * once a caller's flush has given every new Entry its id, and once
 * EntryPruner has decided which ids a bulk delete removed.
 *
 * Indexing is a side effect of storing (or discarding) an entry, never a
 * condition of it succeeding. A search engine that is down, slow, or
 * unconfigured must cost a refresh nothing beyond staler search results — so
 * every method here swallows SearchEngineUnavailableException and logs it
 * rather than letting it propagate. `app:search:reindex` is the repair path
 * for whatever a swallowed failure left out of date; do not "fix" this class
 * by making the exception escape again.
 *
 * NOT `final readonly class`: $configured is a memoised flag, mutated after
 * construction by design (see index()). RefreshRunner calls index() once per
 * feed and a sweep processes up to 50 feeds, so without memoising this
 * service (shared/singleton for the life of the process, same as every other
 * autowired service) would PATCH identical, idempotent settings to the engine
 * up to 50 times per sweep for no behavioural gain. Every other collaborator
 * stays a constructor-promoted `readonly` property; only $configured needs to
 * change after the object is built.
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
     * Idempotent and cheap on Meilisearch's side (a PATCH of the same
     * settings), and it is what makes a freshly enabled container usable
     * without a separate provisioning step or command — so this must still
     * run at least once. Memoised to at most once per process: a failed
     * attempt leaves $configured false, so the next index() call retries
     * rather than assuming a still-unconfigured engine is done for good.
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
     * The Entry-to-IndexedEntry mapping alone: no engine call, nothing
     * swallowed. `app:search:reindex` needs the exact same mapping this class
     * uses at ingest time — a second mapping that drifts from this one is the
     * bug DRY exists to prevent — but it must let SearchEngineUnavailableException
     * reach its caller instead of disappearing into a log line, which rules out
     * reusing index() itself. This static entry point is the shared piece: pure,
     * so it carries no opinion about what happens to a failed write.
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
     * (EntryIngestor::updateFeedMetadata), but an already-indexed entry keeps
     * the feedTitle it was given here until a full app:search:reindex — this
     * class does not propagate a rename onto entries indexed under the old
     * title. Accepted for now: a renamed feed is rare next to the ingest
     * volume a per-entry propagation would cost.
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
