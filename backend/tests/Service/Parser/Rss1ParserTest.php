<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\Rss1Parser;
use PHPUnit\Framework\TestCase;

final class Rss1ParserTest extends TestCase
{
    private function document(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        return $doc;
    }

    public function testImageUrlComesFromContentEncodedThenMediaThenNull(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns="http://purl.org/rss/1.0/"
                     xmlns:dc="http://purl.org/dc/elements/1.1/"
                     xmlns:content="http://purl.org/rss/1.0/modules/content/"
                     xmlns:media="http://search.yahoo.com/mrss/">
              <channel rdf:about="https://rss1.example.com/">
                <title>RSS 1.0 Example</title>
                <link>https://rss1.example.com/</link>
                <description>An RDF site summary</description>
              </channel>
              <item rdf:about="https://e/content">
                <title>Content Item</title>
                <link>https://e/content</link>
                <description>desc content</description>
                <content:encoded>&lt;p&gt;&lt;img src="https://e/c.jpg"&gt;&lt;/p&gt;</content:encoded>
                <dc:creator>Grace Hopper</dc:creator>
              </item>
              <item rdf:about="https://e/media">
                <title>Media Item</title>
                <link>https://e/media</link>
                <description>desc media</description>
                <media:content url="https://e/m.jpg" medium="image"/>
              </item>
              <item rdf:about="https://e/plain">
                <title>Plain Item</title>
                <link>https://e/plain</link>
                <description>desc plain, no image anywhere</description>
              </item>
            </rdf:RDF>
            XML;

        $feed = (new Rss1Parser())->parse($this->document($xml));

        self::assertCount(3, $feed->entries);
        self::assertSame('https://e/c.jpg', $feed->entries[0]->image?->url);
        self::assertSame('https://e/m.jpg', $feed->entries[1]->image?->url);
        self::assertNull($feed->entries[2]->image);
    }

    public function testTitlesAreReducedToPlainText(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns="http://purl.org/rss/1.0/">
              <channel rdf:about="https://rss1.example.com/">
                <title>The &lt;em&gt;Weekly&lt;/em&gt; Review</title>
                <link>https://rss1.example.com/</link>
                <description>desc</description>
              </channel>
              <item rdf:about="https://e/odyssey">
                <title>An &lt;em&gt;Odyssey&lt;/em&gt; for Our Own Time</title>
                <link>https://e/odyssey</link>
              </item>
              <item rdf:about="https://e/datatype">
                <title>&amp;#8220;Datatype&amp;#8221; is an OpenType variable font</title>
                <link>https://e/datatype</link>
              </item>
            </rdf:RDF>
            XML;

        $feed = (new Rss1Parser())->parse($this->document($xml));

        self::assertSame('The Weekly Review', $feed->title);
        self::assertSame('An Odyssey for Our Own Time', $feed->entries[0]->title);
        self::assertSame(
            "\u{201C}Datatype\u{201D} is an OpenType variable font",
            $feed->entries[1]->title,
        );
    }
}
