<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Entity\Feed;
use App\Repository\CategoryRepository;
use App\Service\Category\CategoryNormalizer;
use App\Service\Ingest\EntryCategoryWriter;
use App\Service\Parser\ParsedCategory;
use App\Service\Parser\ParsedEntry;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;

final class EntryCategoryWriterTest extends DbTestCase
{
    private EntryCategoryWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var CategoryRepository $categoryRepository */
        $categoryRepository = $this->em->getRepository(Category::class);
        $this->writer = new EntryCategoryWriter($this->em, $categoryRepository, new CategoryNormalizer());
    }

    private function persistFeed(): Feed
    {
        $feed = new Feed('https://feed.test/' . uniqid('', true));
        $this->em->persist($feed);

        return $feed;
    }

    private function persistEntry(Feed $feed, string $guid): Entry
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $entry = new Entry($feed, $guid, 'https://x.test/' . $guid, 'T', $now, $now);
        $this->em->persist($entry);

        return $entry;
    }

    private function parsed(string $guid, ParsedCategory ...$categories): ParsedEntry
    {
        return new ParsedEntry($guid, null, 'T', null, null, null, null, categories: array_values($categories));
    }

    public function testWritesLinksAndCreatesCategories(): void
    {
        $feed = $this->persistFeed();
        $entry = $this->persistEntry($feed, 'a');

        $parsed = $this->parsed('a', new ParsedCategory('Politics'), new ParsedCategory('World'));
        $this->writer->attach([[$entry, $parsed]]);
        $this->em->flush();
        $this->em->clear();

        $links = $this->em->getRepository(EntryCategory::class)->findBy(['entry' => $entry->getId()]);
        self::assertCount(2, $links);
        $labels = array_map(static fn (EntryCategory $link): string => $link->getLabel(), $links);
        self::assertSame(['Politics', 'World'], $labels);
    }

    public function testTwoFeedsShareOneGlobalCategoryRow(): void
    {
        $feedA = $this->persistFeed();
        $feedB = $this->persistFeed();
        $entryA = $this->persistEntry($feedA, 'a');
        $entryB = $this->persistEntry($feedB, 'b');

        $this->writer->attach([[$entryA, $this->parsed('a', new ParsedCategory('Politics'))]]);
        $this->em->flush();
        $this->writer->attach([[$entryB, $this->parsed('b', new ParsedCategory('POLITICS'))]]);
        $this->em->flush();
        $this->em->clear();

        self::assertCount(1, $this->em->getRepository(Category::class)->findBy(['canonicalKey' => 'politics']));
    }

    public function testDoesNotFlushEarlyWhenNoNewCategoryIsCreated(): void
    {
        $feed = $this->persistFeed();
        $seedEntry = $this->persistEntry($feed, 'seed');
        $this->writer->attach([[$seedEntry, $this->parsed('seed', new ParsedCategory('Politics'))]]);
        $this->em->flush();

        $entry = $this->persistEntry($feed, 'a');
        self::assertNull($entry->getId());

        $this->writer->attach([[$entry, $this->parsed('a', new ParsedCategory('Politics'))]]);

        self::assertNull($entry->getId(), 'attach() must not flush when it created no new Category row');
    }

    public function testFlushesImmediatelyWhenANewCategoryIsCreated(): void
    {
        $feed = $this->persistFeed();
        $entry = $this->persistEntry($feed, 'a');
        $this->em->flush();

        $this->writer->attach([[$entry, $this->parsed('a', new ParsedCategory('Politics'))]]);

        $categories = $this->em->getRepository(Category::class)->findBy(['canonicalKey' => 'politics']);
        self::assertCount(1, $categories);
        self::assertNotNull(
            $categories[0]->getId(),
            'a newly created Category must be flushed inside attach(), not deferred to the caller',
        );
    }

    public function testCreatesANewCategoryEvenWhenAnEarlierOneAlreadyExists(): void
    {
        $feed = $this->persistFeed();
        $seedEntry = $this->persistEntry($feed, 'seed');
        $this->writer->attach([[$seedEntry, $this->parsed('seed', new ParsedCategory('Politics'))]]);
        $this->em->flush();

        $entry = $this->persistEntry($feed, 'a');
        $this->writer->attach([
            [$entry, $this->parsed('a', new ParsedCategory('Politics'), new ParsedCategory('World'))],
        ]);
        $this->em->flush();
        $this->em->clear();

        $links = $this->em->getRepository(EntryCategory::class)->findBy(['entry' => $entry->getId()]);
        self::assertCount(2, $links);
    }

    public function testResolvingMultipleDistinctCategoriesCostsExactlyOneSelect(): void
    {
        $feed = $this->persistFeed();
        $entryA = $this->persistEntry($feed, 'a');
        $entryB = $this->persistEntry($feed, 'b');
        $this->em->flush();

        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->writer->attach([
            [$entryA, $this->parsed('a', new ParsedCategory('Politics'), new ParsedCategory('World'))],
            [$entryB, $this->parsed('b', new ParsedCategory('Tech'))],
        ]);

        self::assertCount(
            1,
            $recorder->queriesMatching('from category'),
            'attach() must resolve a batch of distinct categories in exactly one select, got:'
                . "\n" . implode("\n", $recorder->queries()),
        );
    }
}
