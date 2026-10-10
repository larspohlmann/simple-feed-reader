<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Pass\CoreElement;
use App\Service\Parser\Support\ItemCategoryExtractor;
use App\Tests\Support\FeedFormatParsers;
use PHPUnit\Framework\TestCase;

final class ItemCategoryExtractorTest extends TestCase
{
    private function firstItem(string $xml): CoreElement
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $item = $document->getElementsByTagName('*')->item(0);
        \assert($item instanceof \DOMElement);

        return new CoreElement($item, $item->namespaceURI);
    }

    public function testRss2CategoryTextAndDomain(): void
    {
        $item = $this->firstItem(
            '<item>'
            . '<category domain="https://tax.test">Politics</category>'
            . '<category>World</category>'
            . '</item>',
        );

        $out = ItemCategoryExtractor::extract($item);

        self::assertSame('Politics', $out[0]->label);
        self::assertSame('https://tax.test', $out[0]->scheme);
        self::assertSame('World', $out[1]->label);
        self::assertNull($out[1]->scheme);
    }

    public function testAtomCategoryTermAndScheme(): void
    {
        $item = $this->firstItem(
            '<entry xmlns="http://www.w3.org/2005/Atom">'
            . '<category term="tech" scheme="https://s.test"/>'
            . '</entry>',
        );

        $out = ItemCategoryExtractor::extract($item);

        self::assertSame('tech', $out[0]->label);
        self::assertSame('https://s.test', $out[0]->scheme);
    }

    public function testDublinCoreSubject(): void
    {
        $item = $this->firstItem(
            '<item xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:subject>Science</dc:subject>'
            . '</item>',
        );

        $out = ItemCategoryExtractor::extract($item);

        self::assertSame('Science', $out[0]->label);
        self::assertNull($out[0]->scheme);
    }

    public function testDublinCoreNamespaceIsRequiredNotJustTheLocalName(): void
    {
        $item = $this->firstItem('<item><subject>Not Dublin Core</subject></item>');

        self::assertSame([], ItemCategoryExtractor::extract($item));
    }

    public function testDublinCoreSubjectIsTrimmed(): void
    {
        $item = $this->firstItem(
            '<item xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:subject>  Science  </dc:subject>'
            . '</item>',
        );

        $out = ItemCategoryExtractor::extract($item);

        self::assertSame('Science', $out[0]->label);
    }

    public function testCategoryTermIsTrimmed(): void
    {
        $item = $this->firstItem(
            '<entry xmlns="http://www.w3.org/2005/Atom">'
            . '<category term="  tech  "/>'
            . '</entry>',
        );

        $out = ItemCategoryExtractor::extract($item);

        self::assertSame('tech', $out[0]->label);
    }

    public function testCategorySchemeIsTrimmed(): void
    {
        $item = $this->firstItem(
            '<entry xmlns="http://www.w3.org/2005/Atom">'
            . '<category term="tech" scheme="  https://s.test  "/>'
            . '</entry>',
        );

        $out = ItemCategoryExtractor::extract($item);

        self::assertSame('https://s.test', $out[0]->scheme);
    }

    public function testCategoryDomainFallbackIsTrimmed(): void
    {
        $item = $this->firstItem('<item><category domain="  https://d.test  ">Politics</category></item>');

        $out = ItemCategoryExtractor::extract($item);

        self::assertSame('https://d.test', $out[0]->scheme);
    }

    public function testEmptyCategoriesAreSkipped(): void
    {
        $item = $this->firstItem('<item><category>   </category><category></category></item>');

        self::assertSame([], ItemCategoryExtractor::extract($item));
    }

    public function testRss2ParserPopulatesEntryCategories(): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>'
            . '<item><title>A</title><link>https://x.test/a</link>'
            . '<category domain="https://d.test">Politics</category>'
            . '<category>World</category></item></channel></rss>';
        $feed = FeedFormatParsers::feed($xml);

        self::assertCount(2, $feed->entries[0]->categories);
        self::assertSame('Politics', $feed->entries[0]->categories[0]->label);
    }

    public function testAnExtensionCategoryIsNotAnItemCategory(): void
    {
        $item = $this->firstItem(
            '<item xmlns:media="http://search.yahoo.com/mrss/">'
            . '<media:category>Music</media:category><category>World</category>'
            . '</item>',
        );

        $labels = array_map(static fn ($category) => $category->label, ItemCategoryExtractor::extract($item));

        self::assertSame(['World'], $labels);
    }

    public function testAnAtomEntryReadsOnlyAtomCategories(): void
    {
        $item = $this->firstItem(
            '<entry xmlns="http://www.w3.org/2005/Atom" xmlns:x="urn:example:other">'
            . '<x:category term="Wrong"/><category term="Right"/>'
            . '</entry>',
        );

        $labels = array_map(static fn ($category) => $category->label, ItemCategoryExtractor::extract($item));

        self::assertSame(['Right'], $labels);
    }
}
