<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Service\Catalog\CatalogDocument;
use App\Service\Catalog\Exception\InvalidCatalogDocumentException;
use App\Service\Opml\OpmlBodyReader;
use PHPUnit\Framework\TestCase;

/**
 * The shipped document is validated like production data: it is what an admin
 * imports, and a malformed outline would otherwise become a bad catalog_feed
 * row.
 */
final class CatalogDocumentTest extends TestCase
{
    private function parser(): CatalogDocument
    {
        return new CatalogDocument(new OpmlBodyReader());
    }

    private function shippedOpml(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../resources/catalog/catalog.opml');
    }

    /** @param list<string> $feedOutlines */
    private function opml(
        array $feedOutlines,
        string $categoryAttributes = 'text="Technology" key="technology" icon="memory" color="#3b82f6"',
    ): string {
        return '<opml version="2.0"><head><title>t</title></head><body>'
            . '<outline ' . $categoryAttributes . '>' . implode('', $feedOutlines) . '</outline>'
            . '</body></opml>';
    }

    public function testTheShippedDocumentParsesAndCarriesTheFullCatalog(): void
    {
        $document = $this->parser()->parse($this->shippedOpml());

        self::assertCount(13, $document->categories);
        self::assertSame(111, $document->feedCount());
    }

    public function testEveryCategoryIsWellFormedAndUniquelyKeyed(): void
    {
        $document = $this->parser()->parse($this->shippedOpml());

        $keys = [];
        foreach ($document->categories as $category) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $category->key);
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $category->icon);
            self::assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $category->color);
            self::assertNotSame('', trim($category->name));
            $keys[] = $category->key;
        }

        self::assertSame($keys, array_values(array_unique($keys)));
    }

    public function testEveryFeedUrlIsHttpBoundedAndUniqueAcrossTheWholeDocument(): void
    {
        $document = $this->parser()->parse($this->shippedOpml());

        $urls = [];
        foreach ($document->categories as $category) {
            foreach ($category->feeds as $feed) {
                self::assertNotSame('', trim($feed->title));
                self::assertLessThanOrEqual(750, mb_strlen($feed->url));
                self::assertMatchesRegularExpression('#^https?://#', $feed->url);
                $urls[] = $feed->url;
            }
        }

        self::assertSame($urls, array_values(array_unique($urls)));
    }

    public function testReadsTitleSiteUrlAndDescriptionFromTheStandardAttributes(): void
    {
        $document = $this->parser()->parse($this->opml([
            '<outline type="rss" text="The Verge" xmlUrl="https://www.theverge.com/rss/index.xml"'
            . ' htmlUrl="https://www.theverge.com" description="Tech, science and culture"/>',
        ]));

        $feed = $document->categories[0]->feeds[0];
        self::assertSame('The Verge', $feed->title);
        self::assertSame('https://www.theverge.com/rss/index.xml', $feed->url);
        self::assertSame('https://www.theverge.com', $feed->siteUrl);
        self::assertSame('Tech, science and culture', $feed->description);
    }

    public function testReadsTheTitleAliasWhenTextIsAbsent(): void
    {
        $document = $this->parser()->parse($this->opml([
            '<outline type="rss" title="Aliased" xmlUrl="https://example.com/rss.xml"/>',
        ]));

        self::assertSame('Aliased', $document->categories[0]->feeds[0]->title);
    }

    public function testMalformedOpmlIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse('<opml><body>');
    }

    public function testADuplicateFeedUrlIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse($this->opml([
            '<outline type="rss" text="One" xmlUrl="https://example.com/rss.xml"/>',
            '<outline type="rss" text="Two" xmlUrl="https://example.com/rss.xml"/>',
        ]));
    }

    public function testADuplicateCategoryKeyIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse(
            '<opml version="2.0"><head><title>t</title></head><body>'
            . '<outline text="First" key="dup" icon="memory" color="#000000"/>'
            . '<outline text="Second" key="dup" icon="code" color="#111111"/>'
            . '</body></opml>',
        );
    }

    public function testAnUnknownSourceFormatIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse($this->opml([
            '<outline type="rss" text="X" xmlUrl="https://example.com/x.xml" sourceFormat="json"/>',
        ]));
    }

    public function testANonHttpFeedUrlIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse($this->opml([
            '<outline type="rss" text="X" xmlUrl="ftp://example.com/x.xml"/>',
        ]));
    }

    public function testATopLevelFeedIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse(
            '<opml version="2.0"><head><title>t</title></head><body>'
            . '<outline type="rss" text="Loose" xmlUrl="https://example.com/loose.xml"/>'
            . '</body></opml>',
        );
    }

    public function testABadColourIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse($this->opml([], 'text="X" key="x" icon="memory" color="red"'));
    }

    public function testACategoryWithoutAKeyIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse($this->opml([], 'text="X" icon="memory" color="#000000"'));
    }

    public function testNestedCategoriesAreRejectedRatherThanSilentlyFlattened(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse($this->opml([
            '<outline text="Nested" key="nested" icon="memory" color="#000000">'
            . '<outline type="rss" text="A" xmlUrl="https://example.com/a.xml"/></outline>',
        ]));
    }

    public function testAnEmptyDocumentIsRejected(): void
    {
        $this->expectException(InvalidCatalogDocumentException::class);
        $this->parser()->parse('<opml version="2.0"><head/><body/></opml>');
    }
}
