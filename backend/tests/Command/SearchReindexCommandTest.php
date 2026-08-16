<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SearchReindexCommand;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\Search\Exception\SearchEngineUnavailableException;
use App\Service\Search\Index\SearchIndexWriter;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * SearchReindexCommand is the repair path EntryIndexer's swallowed
 * SearchEngineUnavailableException leans on, and what an operator runs after
 * pointing an existing install at MEILISEARCH_URL for the first time. The
 * writer here is always RecordingSearchIndexWriter — no running Meilisearch
 * — so these tests prove the command's own orchestration: configure-then-clear
 * ordering, id-keyset batching, the reported count, and both non-zero-exit
 * paths (no engine configured; an engine that never answers).
 */
final class SearchReindexCommandTest extends DbTestCase
{
    private function feed(string $title): Feed
    {
        $feed = new Feed('https://example.com/feed-' . uniqid('', true));
        $feed->setTitle($title);
        $this->em->persist($feed);

        return $feed;
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.com/' . $guid,
            'Title ' . $guid,
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );
        $this->em->persist($entry);

        return $entry;
    }

    private function persistEntries(int $count): void
    {
        $feed = $this->feed('Example Feed');
        for ($i = 0; $i < $count; ++$i) {
            $this->entry($feed, 'guid-' . $i);
        }
        $this->em->flush();
    }

    private function tester(
        SearchIndexWriter $writer,
        string $engineUrl = 'http://meilisearch.test',
        int $batchSize = 500,
    ): CommandTester {
        /** @var EntryRepository $entryRepository */
        $entryRepository = self::getContainer()->get(EntryRepository::class);

        $command = new SearchReindexCommand($writer, $entryRepository, $this->em, $engineUrl, $batchSize);

        return new CommandTester($command);
    }

    public function testConfiguresAndClearsBeforeIndexing(): void
    {
        $this->persistEntries(1);
        $writer = new RecordingSearchIndexWriter();

        $tester = $this->tester($writer);
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(['configure', 'clear', 'upsert'], $writer->calls);
    }

    public function testBatchesAcrossMoreThanOneRoundTrip(): void
    {
        $this->persistEntries(5);
        $writer = new RecordingSearchIndexWriter();

        // Batch size 2 over 5 entries forces three round-trips (2, 2, 1) —
        // small enough to prove batching without persisting a table's worth
        // of rows just to exercise it.
        $tester = $this->tester($writer, batchSize: 2);
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(['configure', 'clear', 'upsert', 'upsert', 'upsert'], $writer->calls);
        self::assertSame([2, 2, 1], array_map(count(...), $writer->upserts));
    }

    public function testReportsTheIndexedCount(): void
    {
        $this->persistEntries(3);
        $writer = new RecordingSearchIndexWriter();

        $tester = $this->tester($writer);
        $tester->execute([]);

        self::assertStringContainsString('Reindexed 3 entries.', $tester->getDisplay());
    }

    public function testNoEngineConfiguredExitsNonZeroWithAReadableMessageAndTouchesNothing(): void
    {
        $this->persistEntries(1);
        $writer = new RecordingSearchIndexWriter();

        $tester = $this->tester($writer, engineUrl: '');
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No search engine is configured', $tester->getDisplay());
        self::assertSame([], $writer->calls);
    }

    public function testAnUnreachableEngineExitsNonZeroRatherThanReportingSuccess(): void
    {
        $this->persistEntries(1);
        $writer = new RecordingSearchIndexWriter(new SearchEngineUnavailableException('down'));

        $tester = $this->tester($writer);
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('did not answer', $tester->getDisplay());
        self::assertStringNotContainsString('Reindexed', $tester->getDisplay());
    }
}
