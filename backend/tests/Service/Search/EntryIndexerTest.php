<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Tests\DbTestCase;

/**
 * EntryIndexer turns persisted Entry rows into IndexedEntry documents and
 * hands them to a SearchIndexWriter. Entries are persisted through the real
 * EntityManager (not built by hand) because the one thing worth pinning here
 * — the document really does carry the id Doctrine assigned — only means
 * anything against a real id, and RefreshRunnerTest /
 * FirstFetchRecorderTest cover that this class is actually called after the
 * caller's flush, not before.
 */
final class EntryIndexerTest extends DbTestCase
{
    private function feed(string $title = 'Example Feed'): Feed
    {
        $feed = new Feed('https://example.com/feed-' . uniqid('', true));
        $feed->setTitle($title);
        $this->em->persist($feed);
        $this->em->flush();

        return $feed;
    }

    private function entry(Feed $feed, string $guid = 'g1'): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.com/' . $guid,
            'A Title',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    public function testIndexingConfiguresTheWriterBeforeUpserting(): void
    {
        $writer = new RecordingSearchIndexWriter();
        $indexer = new EntryIndexer($writer, new RecordingLogger());

        $indexer->index([$this->entry($this->feed())]);

        self::assertSame(['configure', 'upsert'], $writer->calls);
    }

    /**
     * RefreshRunner calls index() once per feed and a sweep processes up to
     * 50 feeds — without this, an idempotent but pointless settings PATCH
     * would go out on every one of them.
     */
    public function testConfigureIsSentOnlyOnceAcrossTwoIndexCalls(): void
    {
        $writer = new RecordingSearchIndexWriter();
        $indexer = new EntryIndexer($writer, new RecordingLogger());
        $feed = $this->feed();

        $indexer->index([$this->entry($feed, 'g1')]);
        $indexer->index([$this->entry($feed, 'g2')]);

        self::assertSame(['configure', 'upsert', 'upsert'], $writer->calls);
    }

    /**
     * A configure() failure must not be remembered as success: the next
     * index() call has to retry, which is what lets a search engine that was
     * down at ingest time become usable once it comes back without anyone
     * running a provisioning command.
     */
    public function testAFailedConfigureIsRetriedOnTheNextIndexCall(): void
    {
        $writer = new RecordingSearchIndexWriter(new SearchEngineUnavailableException('down'));
        $logger = new RecordingLogger();
        $indexer = new EntryIndexer($writer, $logger);
        $feed = $this->feed();

        $indexer->index([$this->entry($feed, 'g1')]);
        $indexer->index([$this->entry($feed, 'g2')]);

        // Each call's configure() throws before upsert() ever runs, and each
        // failure is logged separately — proof the second call actually
        // retried rather than treating the first failure as done.
        self::assertSame(['configure', 'configure'], $writer->calls);
        self::assertCount(2, $logger->records);
    }

    public function testTheMappedDocumentCarriesThePlainTextContentAndTheFeedTitle(): void
    {
        $writer = new RecordingSearchIndexWriter();
        $indexer = new EntryIndexer($writer, new RecordingLogger());

        $feed = $this->feed('The Daily Example');
        $entry = $this->entry($feed);
        $entry->setSummary('A plain summary');
        $entry->setContentHtml('<p>Body <strong>text</strong> &amp; more.</p>');
        $this->em->flush();

        $indexer->index([$entry]);

        self::assertCount(1, $writer->upserts);
        $document = $writer->upserts[0][0];
        self::assertSame($entry->getId(), $document->id);
        self::assertSame((int) $feed->getId(), $document->feedId);
        self::assertSame('A Title', $document->title);
        self::assertSame('A plain summary', $document->summary);
        // Reduced to plain text, exactly as PlainText::from() would: tags
        // stripped, entities decoded — never the raw HTML.
        self::assertSame('Body text & more.', $document->content);
        self::assertSame('The Daily Example', $document->feedTitle);
        self::assertSame($entry->getEffectiveDate(), $document->effectiveDate);
    }

    public function testAnEntryWithNoBodyIndexesWithNullContent(): void
    {
        $writer = new RecordingSearchIndexWriter();
        $indexer = new EntryIndexer($writer, new RecordingLogger());

        $indexer->index([$this->entry($this->feed())]);

        self::assertNull($writer->upserts[0][0]->content);
    }

    public function testAnEngineFailureIsLoggedAndSwallowedRatherThanThrown(): void
    {
        $failure = new SearchEngineUnavailableException('down');
        $writer = new RecordingSearchIndexWriter($failure);
        $logger = new RecordingLogger();
        $indexer = new EntryIndexer($writer, $logger);

        // No SearchEngineUnavailableException reaches this test — that is the
        // whole point: indexing must never be able to fail whatever called it.
        $indexer->index([$this->entry($this->feed())]);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame($failure, $logger->records[0]['context']['exception']);
    }

    public function testAnEmptyListDoesNothingAtAll(): void
    {
        $writer = new RecordingSearchIndexWriter();
        $indexer = new EntryIndexer($writer, new RecordingLogger());

        $indexer->index([]);

        self::assertSame([], $writer->calls);
    }

    public function testForgetPassesTheIdsThroughUnchanged(): void
    {
        $writer = new RecordingSearchIndexWriter();
        $indexer = new EntryIndexer($writer, new RecordingLogger());

        $indexer->forget([11, 12, 13]);

        self::assertSame([[11, 12, 13]], $writer->forgets);
    }

    public function testForgetSwallowsAnEngineFailureTheSameWayIndexDoes(): void
    {
        $failure = new SearchEngineUnavailableException('down');
        $writer = new RecordingSearchIndexWriter($failure);
        $logger = new RecordingLogger();
        $indexer = new EntryIndexer($writer, $logger);

        $indexer->forget([11]);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
    }

    public function testForgetWithAnEmptyListDoesNothingAtAll(): void
    {
        $writer = new RecordingSearchIndexWriter();
        $indexer = new EntryIndexer($writer, new RecordingLogger());

        $indexer->forget([]);

        self::assertSame([], $writer->calls);
    }
}
