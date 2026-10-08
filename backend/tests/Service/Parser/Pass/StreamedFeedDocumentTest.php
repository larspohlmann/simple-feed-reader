<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Pass;

use App\Service\Parser\Pass\StreamedFeedDocument;
use PHPUnit\Framework\TestCase;

final class StreamedFeedDocumentTest extends TestCase
{
    private const string RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    public function testTheSkeletonKeepsEverythingButTheEntries(): void
    {
        $skeleton = $this->skeletonOf(
            '<?xml version="1.0"?><rdf:RDF xmlns:rdf="' . self::RDF_NS . '" xmlns="http://purl.org/rss/1.0/">'
            . '<channel rdf:about="https://example.com/" loose:mark="kept"><title><![CDATA[A & B]]></title>'
            . ' <item><title>Entry</title></item><image/></channel><item><title>Top</title></item></rdf:RDF>',
        );

        self::assertSame(
            '<rdf:RDF xmlns:rdf="' . self::RDF_NS . '" xmlns="http://purl.org/rss/1.0/">'
            . '<channel rdf:about="https://example.com/" loose:mark="kept"><title><![CDATA[A & B]]></title> '
            . '<image/></channel></rdf:RDF>',
            (string) $skeleton->saveXML($skeleton->documentElement),
        );
        $channel = $skeleton->getElementsByTagName('channel')->item(0);
        self::assertSame('https://example.com/', $channel?->getAttributeNS(self::RDF_NS, 'about'));
    }

    public function testEachEntryIsExpandedWithItsWholeSubtree(): void
    {
        $parser = $this->streamed(
            '<?xml version="1.0"?><rss xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel>'
            . '<item><title>One</title><itunes:image href="https://img.example.com/1.jpg"/></item>'
            . '<item><title>Two</title></item></channel></rss>',
        );

        self::assertSame(['One', 'Two'], array_map(
            static fn (\DOMElement $item): ?string => $item->getElementsByTagName('title')->item(0)?->textContent,
            $parser->items,
        ));
        $image = $parser->items[0]->getElementsByTagNameNS('http://www.itunes.com/dtds/podcast-1.0.dtd', 'image');
        self::assertSame('https://img.example.com/1.jpg', $image->item(0)?->getAttribute('href'));
    }

    private function skeletonOf(string $xml): \DOMDocument
    {
        $skeleton = $this->streamed($xml)->skeleton;
        self::assertInstanceOf(\DOMDocument::class, $skeleton);

        return $skeleton;
    }

    /** FeedParser collects libxml's errors around the stream; so does this. */
    private function streamed(string $xml): RecordingItemParser
    {
        $parser = new RecordingItemParser();
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            StreamedFeedDocument::open($xml)->parseWith($parser);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        return $parser;
    }
}
