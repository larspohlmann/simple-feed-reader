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
}
