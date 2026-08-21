<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\Atom10Parser;
use App\Service\Parser\ParsedFeed;
use PHPUnit\Framework\TestCase;

final class Atom10ParserTest extends TestCase
{
    private function parse(string $xml): ParsedFeed
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);

        return (new Atom10Parser())->parse($document);
    }

    public function testEntryImageComesFromMediaThumbnailEnclosureOrInlineImg(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
              <title>Atom Image Example</title>
              <link href="https://atom.example.com/" rel="alternate"/>
              <entry>
                <title>Media thumbnail entry</title>
                <link rel="alternate" href="https://e/media"/>
                <id>urn:uuid:media</id>
                <media:thumbnail url="https://e/t.jpg"/>
              </entry>
              <entry>
                <title>Enclosure entry</title>
                <link rel="alternate" href="https://e/enclosure"/>
                <link rel="enclosure" type="image/png" href="https://e/enc.png"/>
                <id>urn:uuid:enclosure</id>
              </entry>
              <entry>
                <title>Inline image entry</title>
                <link rel="alternate" href="https://e/inline"/>
                <id>urn:uuid:inline</id>
                <content type="html">&lt;p&gt;Body &lt;img src="https://e/inline.jpg"&gt; text&lt;/p&gt;</content>
              </entry>
              <entry>
                <title>No image entry</title>
                <link rel="alternate" href="https://e/none"/>
                <id>urn:uuid:none</id>
              </entry>
            </feed>
            XML;

        $feed = $this->parse($xml);

        self::assertCount(4, $feed->entries);
        self::assertSame('https://e/t.jpg', $feed->entries[0]->image?->url);
        self::assertSame('https://e/enc.png', $feed->entries[1]->image?->url);
        self::assertSame('https://e/inline.jpg', $feed->entries[2]->image?->url);
        self::assertNull($feed->entries[3]->image);
    }

    public function testEntryImageFallsBackToAnImgInAnHtmlSummary(): void
    {
        // An Atom entry whose only picture is an <img> in a type="html"
        // <summary> and which carries no <content> element at all.
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Summary Image Example</title>
              <link href="https://atom.example.com/" rel="alternate"/>
              <entry>
                <title>Summary-only image entry</title>
                <link rel="alternate" href="https://e/summary"/>
                <id>urn:uuid:summary</id>
                <summary type="html">&lt;p&gt;Lead &lt;img src="https://e/summary.jpg"&gt; text&lt;/p&gt;</summary>
              </entry>
            </feed>
            XML;

        $feed = $this->parse($xml);

        self::assertSame('https://e/summary.jpg', $feed->entries[0]->image?->url);
    }

    public function testEntryImageFallsBackToAnImgInAnXhtmlSummary(): void
    {
        // A type="xhtml" summary carries the <img> as a real nested element, so
        // its markup must be serialized before the <img> can be seen.
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Xhtml Summary Example</title>
              <link href="https://atom.example.com/" rel="alternate"/>
              <entry>
                <title>Xhtml summary entry</title>
                <link rel="alternate" href="https://e/xhtml"/>
                <id>urn:uuid:xhtml</id>
                <summary type="xhtml">
                  <div xmlns="http://www.w3.org/1999/xhtml"><p><img src="https://e/xhtml.jpg"/> text</p></div>
                </summary>
              </entry>
            </feed>
            XML;

        $feed = $this->parse($xml);

        self::assertSame('https://e/xhtml.jpg', $feed->entries[0]->image?->url);
    }

    public function testEntryContentImageStillWinsOverASummaryImage(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Precedence Example</title>
              <link href="https://atom.example.com/" rel="alternate"/>
              <entry>
                <title>Both content and summary</title>
                <link rel="alternate" href="https://e/both"/>
                <id>urn:uuid:both</id>
                <summary type="html">&lt;img src="https://e/summary.jpg"&gt;</summary>
                <content type="html">&lt;p&gt;&lt;img src="https://e/content.jpg"&gt;&lt;/p&gt;</content>
              </entry>
            </feed>
            XML;

        $feed = $this->parse($xml);

        self::assertSame('https://e/content.jpg', $feed->entries[0]->image?->url);
    }

    public function testMediaImageWinsWhenEveryImageSourceIsPresent(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
              <title>Precedence Example</title>
              <link href="https://atom.example.com/" rel="alternate"/>
              <entry>
                <title>Every source entry</title>
                <link rel="alternate" href="https://e/every"/>
                <link rel="enclosure" type="image/png" href="https://e/enc.png"/>
                <id>urn:uuid:every</id>
                <media:thumbnail url="https://e/media.jpg"/>
                <image_big url="https://e/custom.jpg" width="640"/>
                <summary type="html">&lt;img src="https://e/summary.jpg"&gt;</summary>
                <content type="html">&lt;img src="https://e/content.jpg"&gt;</content>
              </entry>
            </feed>
            XML;

        $feed = $this->parse($xml);

        self::assertSame('https://e/media.jpg', $feed->entries[0]->image?->url);
    }

    public function testEnclosureWinsOverCustomImageAndBodyImages(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Precedence Example</title>
              <link href="https://atom.example.com/" rel="alternate"/>
              <entry>
                <title>Enclosure over rest</title>
                <link rel="alternate" href="https://e/enc-over"/>
                <link rel="enclosure" type="image/png" href="https://e/enc.png"/>
                <id>urn:uuid:enc-over</id>
                <image_big url="https://e/custom.jpg" width="640"/>
                <summary type="html">&lt;img src="https://e/summary.jpg"&gt;</summary>
                <content type="html">&lt;img src="https://e/content.jpg"&gt;</content>
              </entry>
            </feed>
            XML;

        $feed = $this->parse($xml);

        self::assertSame('https://e/enc.png', $feed->entries[0]->image?->url);
    }

    public function testCustomImageElementWinsOverBodyImages(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Precedence Example</title>
              <link href="https://atom.example.com/" rel="alternate"/>
              <entry>
                <title>Custom over body</title>
                <link rel="alternate" href="https://e/custom-over"/>
                <id>urn:uuid:custom-over</id>
                <image_big url="https://e/custom.jpg" width="640"/>
                <summary type="html">&lt;img src="https://e/summary.jpg"&gt;</summary>
                <content type="html">&lt;img src="https://e/content.jpg"&gt;</content>
              </entry>
            </feed>
            XML;

        $feed = $this->parse($xml);

        self::assertSame('https://e/custom.jpg', $feed->entries[0]->image?->url);
    }

    public function testEntryImageComesFromACustomImageBigElement(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Custom Image Example</title>
              <link href="https://atom.example.com/" rel="alternate"/>
              <entry>
                <title>Custom image entry</title>
                <link rel="alternate" href="https://e/custom"/>
                <id>urn:uuid:custom</id>
                <image url="https://images.example.de/small.jpg" width="194" height="126"/>
                <image_big url="https://images.example.de/big.jpg" width="640" height="300"/>
              </entry>
            </feed>
            XML;

        $feed = $this->parse($xml);

        $image = $feed->entries[0]->image;
        self::assertNotNull($image);
        self::assertSame('https://images.example.de/big.jpg', $image->url);
        self::assertSame(640, $image->width);
    }

    public function testTitlesAreReducedToPlainText(): void
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>The &lt;em&gt;Weekly&lt;/em&gt; Review</title>
              <link href="https://atom.example.com/" rel="alternate"/>
              <entry>
                <title>An &lt;em&gt;Odyssey&lt;/em&gt; for Our Own Time</title>
                <link rel="alternate" href="https://e/odyssey"/>
                <id>urn:uuid:odyssey</id>
              </entry>
              <entry>
                <title>&amp;#8220;Datatype&amp;#8221; is an OpenType variable font</title>
                <link rel="alternate" href="https://e/datatype"/>
                <id>urn:uuid:datatype</id>
              </entry>
            </feed>
            XML;

        $feed = $this->parse($xml);

        self::assertSame('The Weekly Review', $feed->title);
        self::assertSame('An Odyssey for Our Own Time', $feed->entries[0]->title);
        self::assertSame(
            "\u{201C}Datatype\u{201D} is an OpenType variable font",
            $feed->entries[1]->title,
        );
    }
}
