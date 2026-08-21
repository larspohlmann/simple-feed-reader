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
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
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

    public function testReadsACustomImageBigElementWhenTheItemHasNoStandardImage(): void
    {
        // A utopia.de-shaped item: no media:*, no enclosure, no inline <img> —
        // only the non-standard <image>/<image_big> item elements. The larger
        // variant wins, and its declared dimensions are kept.
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Utopia</title>
                    <link>https://utopia.de/</link>
                    <description>Nachhaltigkeit</description>
                    <item>
                        <title>Custom image item</title>
                        <link>https://utopia.de/ratgeber/x</link>
                        <description>Plain text, no inline image at all.</description>
                        <image url="https://images.utopia.de/x/w:194/h:126/small.jpg" width="194" height="126"/>
                        <image_big url="https://images.utopia.de/x/w:640/h:300/big.jpg" width="640" height="300"/>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        self::assertCount(1, $feed->entries);
        $image = $feed->entries[0]->image;
        self::assertNotNull($image);
        self::assertSame('https://images.utopia.de/x/w:640/h:300/big.jpg', $image->url);
        self::assertSame(640, $image->width);
        self::assertSame(300, $image->height);
    }

    public function testMediaImageWinsWhenEveryImageSourceIsPresent(): void
    {
        // Media RSS outranks an enclosure, a custom <image_big> and an inline
        // <img> alike: a dedicated feed image must never lose to a body picture.
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
                <channel>
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <item>
                        <title>Every source item</title>
                        <link>https://example.com/every</link>
                        <description>&lt;p&gt;&lt;img src="https://e/inline.jpg"&gt;&lt;/p&gt;</description>
                        <enclosure url="https://e/enclosure.jpg" type="image/jpeg"/>
                        <media:content url="https://e/media.jpg" medium="image" width="800"/>
                        <image_big url="https://e/custom.jpg" width="640"/>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        self::assertSame('https://e/media.jpg', $feed->entries[0]->image?->url);
    }

    public function testEnclosureWinsOverCustomImageAndInlineImg(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <item>
                        <title>Enclosure over rest</title>
                        <link>https://example.com/enc</link>
                        <description>&lt;p&gt;&lt;img src="https://e/inline.jpg"&gt;&lt;/p&gt;</description>
                        <enclosure url="https://e/enclosure.jpg" type="image/jpeg"/>
                        <image_big url="https://e/custom.jpg" width="640"/>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        self::assertSame('https://e/enclosure.jpg', $feed->entries[0]->image?->url);
    }

    public function testCustomImageElementWinsOverAnInlineImg(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    <link>https://example.com/</link>
                    <description>Example feed</description>
                    <item>
                        <title>Custom over inline</title>
                        <link>https://example.com/custom-over-inline</link>
                        <description>&lt;p&gt;&lt;img src="https://e/inline.jpg"&gt;&lt;/p&gt;</description>
                        <image_big url="https://e/custom.jpg" width="640" height="300"/>
                    </item>
                </channel>
            </rss>
            XML;

        $feed = (new Rss2Parser())->parse($this->document($xml));

        $image = $feed->entries[0]->image;
        self::assertNotNull($image);
        self::assertSame('https://e/custom.jpg', $image->url);
        self::assertSame(640, $image->width);
    }

    public function testTitlesAreReducedToPlainText(): void
    {
        // Both the feed and the item title carry entity-escaped HTML: the <em>
        // markup and the &#8220;/&#8221; curly-quote references that real feeds
        // ship. The XML reader decodes those one level, leaving literal tags and
        // references that must not surface in the reader.
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
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
