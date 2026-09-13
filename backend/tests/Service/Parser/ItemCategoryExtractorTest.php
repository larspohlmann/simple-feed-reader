<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\ItemCategoryExtractor;
use App\Service\Parser\Rss2Parser;
use PHPUnit\Framework\TestCase;

final class ItemCategoryExtractorTest extends TestCase
{
    private function firstItem(string $xml): \DOMElement
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $item = $doc->getElementsByTagName('*')->item(0);
        \assert($item instanceof \DOMElement);

        // Return the element that actually holds the categories: the wrapper root.
        return $item;
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
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $feed = (new Rss2Parser())->parse($doc);

        self::assertCount(2, $feed->entries[0]->categories);
        self::assertSame('Politics', $feed->entries[0]->categories[0]->label);
    }
}
