<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\FeedImageExtractor;
use PHPUnit\Framework\TestCase;

final class FeedImageExtractorTest extends TestCase
{
    private const string RSS1_NS = 'http://purl.org/rss/1.0/';
    private const string ATOM_NS = 'http://www.w3.org/2005/Atom';

    private function document(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);

        return $document;
    }

    private function rss2Channel(string $imageMarkup): \DOMElement
    {
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration.
        $document = $this->document(/** @lang TEXT */ <<<XML
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    $imageMarkup
                </channel>
            </rss>
            XML);
        $channel = $document->getElementsByTagName('channel')->item(0);
        self::assertInstanceOf(\DOMElement::class, $channel);

        return $channel;
    }

    public function testReadsTheRss2ChannelImage(): void
    {
        $channel = $this->rss2Channel('<image><url>https://example.com/logo.png</url></image>');

        self::assertSame('https://example.com/logo.png', FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testRss2ChannelWithoutAnImageYieldsNull(): void
    {
        self::assertNull(FeedImageExtractor::fromRss2Channel($this->rss2Channel('')));
    }

    public function testRss2ImageWithoutAUrlYieldsNull(): void
    {
        $channel = $this->rss2Channel('<image><title>Logo</title></image>');

        self::assertNull(FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testUpgradesAProtocolRelativeUrl(): void
    {
        $channel = $this->rss2Channel('<image><url>//cdn.example.com/logo.png</url></image>');

        self::assertSame('https://cdn.example.com/logo.png', FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testDropsAPlainHttpUrl(): void
    {
        $channel = $this->rss2Channel('<image><url>http://example.com/logo.png</url></image>');

        self::assertNull(FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testDropsASiteRelativeUrl(): void
    {
        $channel = $this->rss2Channel('<image><url>/img/logo.png</url></image>');

        self::assertNull(FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testDropsAUrlOverTheColumnLimit(): void
    {
        $tooLong = 'https://example.com/' . str_repeat('a', 2048) . '.png';
        $channel = $this->rss2Channel('<image><url>' . $tooLong . '</url></image>');

        self::assertNull(FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testKeepsAUrlAtExactlyTheColumnLimit(): void
    {
        // URL_MAX itself must still round-trip: only a length OVER the limit
        // is unusable, and the boundary is exactly where an off-by-one in the
        // comparison would flip.
        $prefix = 'https://example.com/';
        $atLimit = $prefix . str_repeat('a', 2048 - mb_strlen($prefix));
        self::assertSame(2048, mb_strlen($atLimit));
        $channel = $this->rss2Channel('<image><url>' . $atLimit . '</url></image>');

        self::assertSame($atLimit, FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testCountsTheLimitInCharactersNotBytes(): void
    {
        // 'ü' is one character but two UTF-8 bytes. At exactly URL_MAX
        // characters this URL is comfortably over URL_MAX bytes, so a
        // byte-counting check would wrongly reject it while the
        // character-counting one the persisted column actually needs stays
        // within range.
        $prefix = 'https://example.com/';
        $atLimit = $prefix . str_repeat('ü', 2048 - mb_strlen($prefix));
        self::assertSame(2048, mb_strlen($atLimit));
        self::assertGreaterThan(2048, strlen($atLimit));
        $channel = $this->rss2Channel('<image><url>' . $atLimit . '</url></image>');

        self::assertSame($atLimit, FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testReadsTheRss1ImageFromTheRdfRoot(): void
    {
        $document = $this->document(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns="http://purl.org/rss/1.0/">
                <channel rdf:about="https://example.com/">
                    <title>Example</title>
                    <image rdf:resource="https://example.com/logo.png"/>
                </channel>
                <image rdf:about="https://example.com/logo.png">
                    <title>Example</title>
                    <url>https://example.com/logo.png</url>
                </image>
            </rdf:RDF>
            XML);

        self::assertSame(
            'https://example.com/logo.png',
            FeedImageExtractor::fromRss1Document($document, self::RSS1_NS),
        );
    }

    public function testSkipsAnRdfRootImageElementInADifferentNamespaceToFindTheRealOne(): void
    {
        // A same-local-name, same-position (direct child of the RDF root)
        // decoy comes FIRST, in a different namespace, with a deliberately
        // different URL. Proves two things at once: that
        // XmlHelper::childElement()'s namespace filter actually rejects a
        // wrong-namespace candidate rather than matching by local name alone,
        // and that rejecting it SKIPS to the next sibling rather than
        // abandoning the search — a decoy earlier in document order must not
        // hide the real image later in it.
        $document = $this->document(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns:other="urn:example:other"
                     xmlns="http://purl.org/rss/1.0/">
                <channel rdf:about="https://example.com/"><title>Example</title></channel>
                <other:image rdf:about="https://wrong.example/">
                    <url>https://wrong.example/should-be-skipped.png</url>
                </other:image>
                <image rdf:about="https://example.com/logo.png">
                    <url>https://example.com/logo.png</url>
                </image>
            </rdf:RDF>
            XML);

        self::assertSame(
            'https://example.com/logo.png',
            FeedImageExtractor::fromRss1Document($document, self::RSS1_NS),
        );
    }

    public function testRss1DocumentWithoutAnImageYieldsNull(): void
    {
        $document = $this->document(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
                     xmlns="http://purl.org/rss/1.0/">
                <channel rdf:about="https://example.com/"><title>Example</title></channel>
            </rdf:RDF>
            XML);

        self::assertNull(FeedImageExtractor::fromRss1Document($document, self::RSS1_NS));
    }

    public function testReadsTheAtomLogo(): void
    {
        $document = $this->document(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
                <title>Example</title>
                <logo>https://example.com/banner.png</logo>
                <icon>https://example.com/favicon.ico</icon>
            </feed>
            XML);
        $root = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);

        self::assertSame(
            'https://example.com/banner.png',
            FeedImageExtractor::fromAtomFeed($root, self::ATOM_NS),
        );
    }

    public function testAtomIconIsNotUsedAsTheFeedImage(): void
    {
        $document = $this->document(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
                <title>Example</title>
                <icon>https://example.com/favicon.ico</icon>
            </feed>
            XML);
        $root = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);

        self::assertNull(FeedImageExtractor::fromAtomFeed($root, self::ATOM_NS));
    }
}
