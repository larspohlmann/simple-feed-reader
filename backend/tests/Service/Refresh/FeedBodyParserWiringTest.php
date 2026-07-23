<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Refresh\FeedBodyParser;
use App\Tests\Service\Scraper\ScrapedFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * RefreshRunnerTest hands the dispatcher a hand-built locator, so it stays
 * green even if the container's app.feed_body_parser tag collects nothing —
 * the same silent failure mode HtmlItemExtractorWiringTest documents for the
 * scrape layers. This test drives the REAL container wiring: both formats
 * must resolve through the tagged locator, and an unknown format must take
 * the xml fallback instead of blowing up on a legacy row.
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

        $rss = <<<'XML'
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
     * A sourceFormat the locator does not know (a row written by a future
     * version, or a format since removed) falls back to 'xml'. For a non-XML
     * body that surfaces as FeedParseException — which IS the proof the xml
     * parser handled it: the dispatcher neither matched 'jsonfeed' nor threw
     * a locator NotFoundException.
     */
    public function testUnknownFormatFallsBackToTheXmlParser(): void
    {
        $feed = new Feed('https://example.com/feed.json');
        $feed->setSourceFormat('jsonfeed');

        $this->expectException(FeedParseException::class);
        $this->parser()->parse($feed, '{"version": "https://jsonfeed.org/version/1", "items": []}');
    }
}
