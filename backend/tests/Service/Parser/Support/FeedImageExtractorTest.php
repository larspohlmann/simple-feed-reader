<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Pass\CoreElement;
use App\Service\Parser\Support\FeedImageExtractor;
use PHPUnit\Framework\TestCase;

final class FeedImageExtractorTest extends TestCase
{
    private const string RSS1_NS = 'http://purl.org/rss/1.0/';
    private const string ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const string ITUNES_NS = 'http://www.itunes.com/dtds/podcast-1.0.dtd';

    private function document(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);

        return $document;
    }

    private function rss2Channel(string $imageMarkup): CoreElement
    {
        $document = $this->document(/** @lang TEXT */ <<<XML
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    $imageMarkup
                </channel>
            </rss>
            XML);

        return self::rss2ChannelOf($document);
    }

    private static function rss2ChannelOf(\DOMDocument $document): CoreElement
    {
        $channel = $document->getElementsByTagName('channel')->item(0);
        self::assertInstanceOf(\DOMElement::class, $channel);

        return CoreElement::inOwnNamespace($channel);
    }

    private static function rss1Root(\DOMDocument $document): CoreElement
    {
        $root = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);

        return new CoreElement($root, self::RSS1_NS);
    }

    private function atomRoot(string $xml): CoreElement
    {
        $root = $this->document($xml)->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);

        return new CoreElement($root, self::ATOM_NS);
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
        $prefix = 'https://example.com/';
        $atLimit = $prefix . str_repeat('a', 2048 - mb_strlen($prefix));
        self::assertSame(2048, mb_strlen($atLimit));
        $channel = $this->rss2Channel('<image><url>' . $atLimit . '</url></image>');

        self::assertSame($atLimit, FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testCountsTheLimitInCharactersNotBytes(): void
    {
        // 'ü' is two UTF-8 bytes: at URL_MAX characters this URL is far over URL_MAX bytes.
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
            FeedImageExtractor::fromRss1Root(self::rss1Root($document)),
        );
    }

    public function testSkipsAnRdfRootImageElementInADifferentNamespaceToFindTheRealOne(): void
    {
        // The decoy comes first, so rejecting it must skip on to the real image rather than stop.
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
            FeedImageExtractor::fromRss1Root(self::rss1Root($document)),
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

        self::assertNull(FeedImageExtractor::fromRss1Root(self::rss1Root($document)));
    }

    public function testReadsTheAtomLogo(): void
    {
        $root = $this->atomRoot(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
                <title>Example</title>
                <logo>https://example.com/banner.png</logo>
                <icon>https://example.com/favicon.ico</icon>
            </feed>
            XML);

        self::assertSame(
            'https://example.com/banner.png',
            FeedImageExtractor::fromAtomFeed($root),
        );
    }

    public function testAtomIconIsNotUsedAsTheFeedImage(): void
    {
        $root = $this->atomRoot(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
                <title>Example</title>
                <icon>https://example.com/favicon.ico</icon>
            </feed>
            XML);

        self::assertNull(FeedImageExtractor::fromAtomFeed($root));
    }

    public function testALeadingItunesImageDoesNotHideTheChannelImage(): void
    {
        $channel = $this->rss2Channel(
            '<itunes:image xmlns:itunes="' . self::ITUNES_NS . '" href="https://example.com/avatar.jpg"/>'
            . '<image><url>https://example.com/logo.png</url></image>',
        );

        self::assertSame('https://example.com/logo.png', FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testFallsBackToTheChannelsPodcastArtwork(): void
    {
        $channel = $this->rss2Channel(
            '<itunes:image xmlns:itunes="' . self::ITUNES_NS . '" href="https://example.com/avatar.jpg"/>',
        );

        self::assertSame('https://example.com/avatar.jpg', FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testDropsPlainHttpPodcastArtwork(): void
    {
        $channel = $this->rss2Channel(
            '<itunes:image xmlns:itunes="' . self::ITUNES_NS . '" href="http://example.com/avatar.jpg"/>',
        );

        self::assertNull(FeedImageExtractor::fromRss2Channel($channel));
    }

    public function testAnAtomFeedWithoutALogoFallsBackToItsPodcastArtwork(): void
    {
        $root = $this->atomRoot(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <feed xmlns="http://www.w3.org/2005/Atom" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
                <title>Example</title>
                <itunes:image href="https://example.com/avatar.jpg"/>
            </feed>
            XML);

        self::assertSame('https://example.com/avatar.jpg', FeedImageExtractor::fromAtomFeed($root));
    }

    public function testAnAtomLogoBeatsThePodcastArtwork(): void
    {
        $root = $this->atomRoot(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <feed xmlns="http://www.w3.org/2005/Atom" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd">
                <itunes:image href="https://example.com/avatar.jpg"/>
                <logo>https://example.com/logo.png</logo>
            </feed>
            XML);

        self::assertSame('https://example.com/logo.png', FeedImageExtractor::fromAtomFeed($root));
    }

    public function testReadsTheImageOfAnRss2FeedInADefaultNamespace(): void
    {
        $document = $this->document(/** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0" xmlns="http://backend.userland.com/rss2">
                <channel>
                    <image><url>https://example.com/logo.png</url></image>
                </channel>
            </rss>
            XML);

        self::assertSame(
            'https://example.com/logo.png',
            FeedImageExtractor::fromRss2Channel(self::rss2ChannelOf($document)),
        );
    }
}
