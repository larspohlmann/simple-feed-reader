<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Refresh\FeedBodyParser;
use App\Tests\Service\Scraper\ScrapedFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Drives the real container wiring, which the refresh tests' hand-built locator (RefreshRunners) cannot prove: every
 * format resolves through the app.feed_body_parser tag, and an unknown one takes the xml fallback.
 */
final class FeedBodyParserWiringTest extends KernelTestCase
{
    use ScrapedFixtures;

    private function parser(): FeedBodyParser
    {
        self::bootKernel();
        $parser = self::getContainer()->get(FeedBodyParser::class);
        self::assertInstanceOf(FeedBodyParser::class, $parser);

        return $parser;
    }

    public function testXmlFormatResolvesToTheFeedDocumentParser(): void
    {
        $feed = new Feed('https://example.com/feed.xml'); // sourceFormat defaults to 'xml'

        $rss = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>Wired</title>
            <item><title>Post</title><link>https://example.com/p</link><guid>w-1</guid></item>
            </channel></rss>
            XML;

        $parsed = $this->parser()->parse($feed, $rss);

        self::assertSame('Wired', $parsed->title);
        self::assertCount(1, $parsed->entries);
    }

    public function testScrapedFormatResolvesToTheHtmlExtractor(): void
    {
        $feed = new Feed('https://www.tagesschau.de/');
        $feed->setSourceFormat('scraped');

        $parsed = $this->parser()->parse($feed, $this->scrapedFixture('tagesschau-2026-07-23.html'));

        self::assertGreaterThanOrEqual(20, \count($parsed->entries));
    }

    /**
     * An unknown sourceFormat (a newer version's row, or a removed format) falls back to 'xml': a non-XML body then
     * fails as FeedParseException, not a locator NotFoundException, and the message names the missing format.
     */
    public function testUnknownFormatFallsBackToTheXmlParserAndNamesTheGap(): void
    {
        $feed = new Feed('https://example.com/feed.json');
        $feed->setSourceFormat('jsonfeed');

        $this->expectException(FeedParseException::class);
        $this->expectExceptionMessageMatches('/^No parser for source format "jsonfeed"; tried xml: ./');
        $this->parser()->parse($feed, '{"version": "https://jsonfeed.org/version/1", "items": []}');
    }

    public function testWpJsonFormatResolvesToTheWordPressParser(): void
    {
        $feed = new \App\Entity\Feed('https://site.example/wp-json/wp/v2/posts?per_page=50&_embed');
        $feed->setSourceFormat(\App\Enum\SourceFormat::WP_JSON);

        $body = '[{"id":1,"link":"https://site.example/p","title":{"rendered":"Post"},'
            . '"content":{"rendered":"<p>Body.</p>"},"date_gmt":"2026-08-20T10:00:00"}]';

        $parsed = $this->parser()->parse($feed, $body);

        self::assertCount(1, $parsed->entries);
        self::assertSame('Post', $parsed->entries[0]->title);
    }
}
