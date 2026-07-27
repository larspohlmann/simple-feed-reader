<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\CatalogDocument;
use App\Service\Catalog\CatalogImporter;
use App\Service\Catalog\CatalogImportMode;
use App\Service\Catalog\ParsedCatalog;
use App\Tests\DbTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class CatalogImporterTest extends DbTestCase
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function importer(): CatalogImporter
    {
        $importer = self::getContainer()->get(CatalogImporter::class);
        self::assertInstanceOf(CatalogImporter::class, $importer);

        return $importer;
    }

    /**
     * @param list<array{title: string, url: string}> $feeds
     */
    private function document(array $feeds, string $key = 'technology', string $name = 'Technology'): ParsedCatalog
    {
        $outlines = '';
        foreach ($feeds as $feed) {
            $outlines .= \sprintf(
                '<outline type="rss" text="%s" xmlUrl="%s"/>',
                htmlspecialchars($feed['title'], \ENT_XML1),
                htmlspecialchars($feed['url'], \ENT_XML1),
            );
        }

        $parser = self::getContainer()->get(CatalogDocument::class);
        self::assertInstanceOf(CatalogDocument::class, $parser);

        return $parser->parse(\sprintf(
            '<opml version="2.0"><head><title>t</title></head><body>'
            . '<outline text="%s" key="%s" icon="memory" color="#3b82f6">%s</outline>'
            . '</body></opml>',
            $name,
            $key,
            $outlines,
        ));
    }

    /**
     * Two empty categories, in the REVERSE of alphabetical order, so a test can
     * tell "positioned by document order" apart from "fell back to the name
     * tiebreak" — the two would otherwise look identical.
     */
    private function twoCategoryDocumentInReverseAlphabeticalOrder(): ParsedCatalog
    {
        $parser = self::getContainer()->get(CatalogDocument::class);
        self::assertInstanceOf(CatalogDocument::class, $parser);

        return $parser->parse(
            '<opml version="2.0"><head><title>t</title></head><body>'
            . '<outline text="Zebra" key="zebra" icon="memory" color="#3b82f6"></outline>'
            . '<outline text="Apple" key="apple" icon="memory" color="#3b82f6"></outline>'
            . '</body></opml>',
        );
    }

    public function testAFirstImportCreatesEverything(): void
    {
        $result = $this->importer()->import(
            $this->document([
                ['title' => 'The Verge', 'url' => 'https://www.theverge.com/rss/index.xml'],
                ['title' => 'Ars Technica', 'url' => 'https://feeds.arstechnica.com/arstechnica/index'],
            ]),
            CatalogImportMode::Merge,
        );

        self::assertSame(1, $result->categoriesCreated);
        self::assertSame(2, $result->feedsCreated);
        self::assertSame(0, $result->feedsUpdated);
        self::assertSame(0, $result->feedsRemoved);
    }

    public function testReimportingUpdatesInPlaceAndKeepsTheCachedFavicon(): void
    {
        $document = $this->document([
            ['title' => 'The Verge', 'url' => 'https://www.theverge.com/rss/index.xml'],
        ]);
        $this->importer()->import($document, CatalogImportMode::Merge);

        $feed = $this->em()->getRepository(CatalogFeed::class)->findOneBy([
            'url' => 'https://www.theverge.com/rss/index.xml',
        ]);
        self::assertNotNull($feed);
        $feed->storeFavicon('https://www.theverge.com/favicon.ico', 'PNGBYTES', 'image/png', new \DateTimeImmutable());
        $this->em()->flush();

        $renamed = $this->document([
            ['title' => 'The Verge (renamed)', 'url' => 'https://www.theverge.com/rss/index.xml'],
        ]);
        $result = $this->importer()->import($renamed, CatalogImportMode::Merge);

        self::assertSame(0, $result->feedsCreated);
        self::assertSame(1, $result->feedsUpdated);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(CatalogFeed::class)->findOneBy([
            'url' => 'https://www.theverge.com/rss/index.xml',
        ]);
        self::assertNotNull($reloaded);
        self::assertSame('The Verge (renamed)', $reloaded->getTitle());
        self::assertSame('PNGBYTES', $reloaded->getFaviconBytes(), 'a surviving URL keeps its icon');
    }

    public function testMergeLeavesRowsTheDocumentDoesNotMention(): void
    {
        $this->importer()->import(
            $this->document([['title' => 'Keep me', 'url' => 'https://keep.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        $result = $this->importer()->import(
            $this->document([['title' => 'New', 'url' => 'https://new.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        self::assertSame(0, $result->feedsRemoved);
        self::assertCount(2, $this->em()->getRepository(CatalogFeed::class)->findAll());
    }

    public function testReplaceRemovesRowsTheDocumentDoesNotMention(): void
    {
        $this->importer()->import(
            $this->document([['title' => 'Retired', 'url' => 'https://retired.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        $result = $this->importer()->import(
            $this->document([['title' => 'New', 'url' => 'https://new.example.com/rss.xml']]),
            CatalogImportMode::Replace,
        );

        self::assertSame(1, $result->feedsRemoved);

        $this->em()->clear();
        $remaining = $this->em()->getRepository(CatalogFeed::class)->findAll();
        self::assertCount(1, $remaining);
        self::assertSame('New', $remaining[0]->getTitle());
    }

    public function testReplaceAlsoRemovesACategoryTheDocumentDropped(): void
    {
        $this->importer()->import($this->document([], 'gone', 'Gone'), CatalogImportMode::Merge);
        $result = $this->importer()->import($this->document([]), CatalogImportMode::Replace);

        self::assertSame(1, $result->categoriesRemoved);

        $this->em()->clear();
        self::assertCount(1, $this->em()->getRepository(CatalogCategory::class)->findAll());
    }

    public function testReplaceKeepsALockedFeedTheDocumentNoLongerLists(): void
    {
        $this->importer()->import(
            $this->document([['title' => 'Mine', 'url' => 'https://mine.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        $feed = $this->em()->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Mine']);
        self::assertNotNull($feed);
        $feed->setLocked(true);
        $this->em()->flush();

        $result = $this->importer()->import(
            $this->document([['title' => 'New', 'url' => 'https://new.example.com/rss.xml']]),
            CatalogImportMode::Replace,
        );

        self::assertSame(0, $result->feedsRemoved);
        self::assertSame(1, $result->lockedSkipped);

        $this->em()->clear();
        self::assertCount(2, $this->em()->getRepository(CatalogFeed::class)->findAll());
    }

    public function testALockedFeedIsNotOverwrittenByTheDocument(): void
    {
        $this->importer()->import(
            $this->document([['title' => 'Original', 'url' => 'https://locked.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        $feed = $this->em()->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Original']);
        self::assertNotNull($feed);
        $feed->setLocked(true);
        $this->em()->flush();

        $result = $this->importer()->import(
            $this->document([['title' => 'Renamed by the document', 'url' => 'https://locked.example.com/rss.xml']]),
            CatalogImportMode::Merge,
        );

        self::assertSame(0, $result->feedsUpdated);
        self::assertSame(1, $result->lockedSkipped);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(CatalogFeed::class)->findOneBy([
            'url' => 'https://locked.example.com/rss.xml',
        ]);
        self::assertNotNull($reloaded);
        self::assertSame('Original', $reloaded->getTitle());
    }

    public function testReplaceKeepsACategoryThatStillHoldsALockedFeed(): void
    {
        $this->importer()->import(
            $this->document([['title' => 'Mine', 'url' => 'https://mine.example.com/rss.xml']], 'mine', 'Mine'),
            CatalogImportMode::Merge,
        );

        $feed = $this->em()->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Mine']);
        self::assertNotNull($feed);
        $feed->setLocked(true);
        $this->em()->flush();

        // The document drops the whole 'mine' category. Removing it would
        // cascade to the locked feed, so it has to survive.
        $result = $this->importer()->import($this->document([]), CatalogImportMode::Replace);

        self::assertSame(0, $result->categoriesRemoved);

        $this->em()->clear();
        self::assertCount(1, $this->em()->getRepository(CatalogFeed::class)->findAll());
        self::assertCount(2, $this->em()->getRepository(CatalogCategory::class)->findAll());
    }

    public function testReplaceKeepsALockedCategory(): void
    {
        $this->importer()->import($this->document([], 'mine', 'Mine'), CatalogImportMode::Merge);

        $category = $this->em()->getRepository(CatalogCategory::class)->findOneBy(['key' => 'mine']);
        self::assertNotNull($category);
        $category->setLocked(true);
        $this->em()->flush();

        $result = $this->importer()->import($this->document([]), CatalogImportMode::Replace);

        self::assertSame(0, $result->categoriesRemoved);
        self::assertSame(1, $result->lockedSkipped);
    }

    public function testPositionsFollowDocumentOrder(): void
    {
        $this->importer()->import(
            $this->document([
                ['title' => 'First', 'url' => 'https://first.example.com/rss.xml'],
                ['title' => 'Second', 'url' => 'https://second.example.com/rss.xml'],
            ]),
            CatalogImportMode::Merge,
        );

        $this->em()->clear();
        $second = $this->em()->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Second']);
        self::assertNotNull($second);
        self::assertSame(1, $second->getPosition());
    }

    public function testFirstImportPositionsCategoriesInDocumentOrder(): void
    {
        $this->importer()->import($this->twoCategoryDocumentInReverseAlphabeticalOrder(), CatalogImportMode::Merge);

        $this->em()->clear();
        $ordered = $this->em()->getRepository(CatalogCategory::class)->findAllOrdered();

        self::assertSame(
            ['Zebra', 'Apple'],
            array_map(static fn (CatalogCategory $category): string => $category->getName(), $ordered),
            'a category created on a first import must keep the document order, not fall back to name',
        );
    }
}
