<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\Atom10Parser;
use PHPUnit\Framework\TestCase;

final class Atom10ParserTest extends TestCase
{
    private function parse(string $xml): \App\Service\Parser\ParsedFeed
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);

        return (new Atom10Parser())->parse($document);
    }

    public function testEntryImageComesFromMediaThumbnailEnclosureOrInlineImg(): void
    {
        $xml = <<<'XML'
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

    public function testTitlesAreReducedToPlainText(): void
    {
        $xml = <<<'XML'
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
