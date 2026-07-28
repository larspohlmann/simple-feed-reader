<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\Rss2Parser;
use PHPUnit\Framework\TestCase;

final class Rss2ParserTest extends TestCase
{
    private function document(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);

        return $document;
    }

    public function testExtractsImageUrlFromMediaEnclosureOrInlineHtml(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
                <channel>
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <item>
                        <title>Media item</title>
                        <link>https://example.com/media</link>
                        <description>No inline image here.</description>
                        <media:content url="https://e/a.jpg" medium="image"/>
                    </item>
                    <item>
                        <title>Inline image item</title>
                        <link>https://example.com/inline</link>
                        <description>&lt;p&gt;&lt;img src="https://e/b.jpg"&gt;&lt;/p&gt;</description>
                    </item>
                    <item>
                        <title>Plain item</title>
                        <link>https://example.com/plain</link>
                        <description>Just plain text, no image at all.</description>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        self::assertCount(3, $feed->entries);
        self::assertSame('https://e/a.jpg', $feed->entries[0]->image?->url);
        self::assertSame('https://e/b.jpg', $feed->entries[1]->image?->url);
        self::assertNull($feed->entries[2]->image);
    }

    public function testTitlesAreReducedToPlainText(): void
    {
        // Both the feed and the item title carry entity-escaped HTML: the <em>
        // markup and the &#8220;/&#8221; curly-quote references that real feeds
        // ship. The XML reader decodes those one level, leaving literal tags and
        // references that must not surface in the reader.
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>The &lt;em&gt;Weekly&lt;/em&gt; Review</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <item>
                        <title>An &lt;em&gt;Odyssey&lt;/em&gt; for Our Own Time</title>
                        <link>https://example.com/odyssey</link>
                    </item>
                    <item>
                        <title>&amp;#8220;Datatype&amp;#8221; is an OpenType variable font</title>
                        <link>https://example.com/datatype</link>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        self::assertSame('The Weekly Review', $feed->title);
        self::assertSame('An Odyssey for Our Own Time', $feed->entries[0]->title);
        self::assertSame(
            "\u{201C}Datatype\u{201D} is an OpenType variable font",
            $feed->entries[1]->title,
        );
    }
}
