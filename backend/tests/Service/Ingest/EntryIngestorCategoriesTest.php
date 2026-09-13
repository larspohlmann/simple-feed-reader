<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\CategoryRepository;
use App\Repository\EntryRepository;
use App\Service\Category\CategoryNormalizer;
use App\Service\Ingest\EntryCategoryWriter;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\FeedIngestContext;
use App\Service\Parser\ParsedCategory;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Url\UrlNormalizer;
use App\Tests\DbTestCase;

final class EntryIngestorCategoriesTest extends DbTestCase
{
    private EntryIngestor $ingestor;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var EntryRepository $entryRepository */
        $entryRepository = $this->em->getRepository(Entry::class);
        /** @var CategoryRepository $categoryRepository */
        $categoryRepository = $this->em->getRepository(Category::class);
        $this->ingestor = new EntryIngestor(
            $this->em,
            $entryRepository,
            new EntrySanitizer(),
            new UrlNormalizer(),
            new EntryCategoryWriter($this->em, $categoryRepository, new CategoryNormalizer()),
        );

        $this->feed = new Feed('https://example.com/feed');
        $this->em->persist($this->feed);
        $this->em->flush();
    }

    public function testIngestWritesCategoriesForNewEntriesAndDoesNotChurnOnReingest(): void
    {
        $parsed = $this->parsedFeedWithEntry('guid-1', [
            new ParsedCategory('Politics'),
            new ParsedCategory('World'),
        ]);

        $this->ingestor->ingest($this->feed, $parsed, $this->context());
        $this->em->flush();

        self::assertSame(2, $this->categoryCount());
        self::assertSame(2, $this->entryCategoryCount());

        $this->ingestor->ingest($this->feed, $parsed, $this->context());
        $this->em->flush();

        self::assertSame(2, $this->categoryCount());
        self::assertSame(2, $this->entryCategoryCount());
    }

    /**
     * @param list<ParsedCategory> $categories
     */
    private function parsedFeedWithEntry(string $guid, array $categories): ParsedFeed
    {
        return new ParsedFeed(null, null, null, null, [
            new ParsedEntry(
                guid: $guid,
                url: 'https://example.com/' . $guid,
                title: 'Title',
                author: null,
                summary: null,
                contentHtml: '<p>body</p>',
                publishedAt: null,
                categories: $categories,
            ),
        ]);
    }

    private function context(): FeedIngestContext
    {
        return new FeedIngestContext(new \DateTimeImmutable('2026-07-21T12:00:00Z'), null);
    }

    private function categoryCount(): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM category');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function entryCategoryCount(): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM entry_category');
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
