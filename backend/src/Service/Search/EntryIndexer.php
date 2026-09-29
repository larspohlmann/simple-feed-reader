<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\DependencyInjection\ProcessLifetimeState;
use App\Entity\Entry;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\Model\IndexedEntryModel;
use App\Service\Search\Index\SearchIndexWriter\SearchIndexWriterInterface;
use App\Service\Text\Support\PlainText;
use Psr\Log\LoggerInterface;

/**
 * Keeps the search index in step with the database, after a caller's flush gave each Entry its id and after
 * EntryPruner's bulk delete. Indexing never fails the caller: every method logs SearchEngineUnavailableException,
 * and app:search:reindex is the repair path. An unconfigured engine makes every write a no-op.
 */
#[ProcessLifetimeState('The index settings are pushed once per process')]
final class EntryIndexer
{
    private bool $configured = false;

    public function __construct(
        private readonly SearchIndexWriterInterface $index,
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
        } catch (SearchEngineUnavailableException $exception) {
            $this->logger->error('Failed to index entries', ['exception' => $exception]);
        }
    }

    /**
     * Pushes the idempotent index settings, which makes a freshly enabled engine usable without a provisioning step.
     * At most once per process; a failure leaves $configured false, so the next index() retries.
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
     * The mapping alone, with no engine call and nothing swallowed: app:search:reindex shares it but must see
     * SearchEngineUnavailableException, so it cannot reuse index().
     *
     * @param list<Entry> $entries
     *
     * @return list<IndexedEntryModel>
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
        } catch (SearchEngineUnavailableException $exception) {
            $this->logger->error('Failed to remove entries from the index', ['exception' => $exception]);
        }
    }

    /**
     * An indexed entry keeps this feedTitle until app:search:reindex, even after a refresh renames the feed; accepted,
     * since renames are rare next to the ingest volume propagation would cost.
     */
    private static function toIndexedEntry(Entry $entry): IndexedEntryModel
    {
        return new IndexedEntryModel(
            id: $entry->requireId(),
            feedId: $entry->getFeed()->requireId(),
            title: $entry->getTitle(),
            summary: $entry->getSummary(),
            content: PlainText::fromHtmlBlocks($entry->getContentHtml()),
            feedTitle: $entry->getFeed()->getTitle(),
            effectiveDate: $entry->getEffectiveDate(),
        );
    }
}
