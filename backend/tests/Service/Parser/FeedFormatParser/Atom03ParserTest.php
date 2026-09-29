<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\FeedFormatParser;

use App\Service\Parser\Model\ParsedFeedModel;
use App\Tests\Support\FeedFormatParsers;
use PHPUnit\Framework\TestCase;

final class Atom03ParserTest extends TestCase
{
    private function parse(string $xml): ParsedFeedModel
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);

        return FeedFormatParsers::atom03()->parse($document);
    }

    public function testEntryDateComesFromDublinCoreWhenTheDialectDatesAreAbsent(): void
    {
        // tagesschau and NDR serve Atom 0.3 that omits <issued>/<modified> and
        // carries the timestamp only as Dublin Core <dc:date>.
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://purl.org/atom/ns#" xmlns:dc="http://purl.org/dc/elements/1.1/" version="0.3">
              <title>NDR.de</title>
              <link rel="alternate" href="https://www.ndr.de/"/>
              <entry>
                <title>Ein Beitrag ohne issued-Datum</title>
                <link rel="alternate" href="https://www.ndr.de/beitrag"/>
                <id>urn:ndr:beitrag</id>
                <dc:date>2026-08-13T07:57:57Z</dc:date>
              </entry>
            </feed>
            XML;

        $feed = $this->parse($xml);

        self::assertCount(1, $feed->entries);
        self::assertEquals(
            new \DateTimeImmutable('2026-08-13T07:57:57+00:00'),
            $feed->entries[0]->publishedAt,
        );
    }

    public function testDialectOwnIssuedDateWinsOverDublinCore(): void
    {
        // @lang TEXT: see the note above on the indented heredoc.
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://purl.org/atom/ns#" xmlns:dc="http://purl.org/dc/elements/1.1/" version="0.3">
              <title>Atom 0.3 with both dates</title>
              <link rel="alternate" href="https://example.com/"/>
              <entry>
                <title>Beitrag mit issued</title>
                <link rel="alternate" href="https://example.com/beitrag"/>
                <id>urn:example:beitrag</id>
                <issued>2026-08-13T06:00:00Z</issued>
                <dc:date>2026-08-13T07:57:57Z</dc:date>
              </entry>
            </feed>
            XML;

        $feed = $this->parse($xml);

        self::assertEquals(
            new \DateTimeImmutable('2026-08-13T06:00:00+00:00'),
            $feed->entries[0]->publishedAt,
        );
    }

    public function testCarriesAMediaContentAudioIntoTheEntryAttachments(): void
    {
        $xml = /** @lang TEXT */ <<<'XML'
            <feed xmlns="http://purl.org/atom/ns#" xmlns:media="http://search.yahoo.com/mrss/" version="0.3">
              <title>Feed</title>
              <entry>
                <title>Audio entry</title>
                <link rel="alternate" href="https://e/audio"/>
                <id>urn:uuid:audio</id>
                <media:content url="https://cdn/ep.mp3" type="audio/mpeg"/>
              </entry>
            </feed>
            XML;

        $entry = $this->parse($xml)->entries[0];

        $bundle = $entry->media->mediaBundle;

        self::assertNotNull($bundle);
        self::assertCount(1, $bundle->attachments);
        self::assertSame('https://cdn/ep.mp3', $bundle->attachments[0]->url);
    }
}
