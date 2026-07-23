<?php

declare(strict_types=1);

namespace App\Tests\Service\Preview;

use App\Exception\FeedPreviewException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\FeedParser;
use App\Service\Preview\FeedPreviewService;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FeedPreviewServiceTest extends KernelTestCase
{
    private const URL = 'https://example.com/feed';

    private function service(StubFeedFetcher $fetcher): FeedPreviewService
    {
        $parser = self::getContainer()->get(FeedParser::class);
        self::assertInstanceOf(FeedParser::class, $parser);

        return new FeedPreviewService($fetcher, $parser);
    }

    private function fetcherWithBody(string $xml): StubFeedFetcher
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            self::URL,
            FetchResponse::fetched(self::URL, permanentRedirect: false, body: $xml, etag: null, lastModified: null),
        );

        return $fetcher;
    }

    private function longParagraph(): string
    {
        // ~684 chars of plain text once stripped — comfortably over the 600 minimum.
        return '<p>' . str_repeat('Lorem ipsum dolor sit amet consectetur adipiscing elit. ', 12) . '</p>';
    }

    private function rss(string $itemsXml, string $namespaces = ''): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"
                 xmlns:content="http://purl.org/rss/1.0/modules/content/"
                 xmlns:dc="http://purl.org/dc/elements/1.1/"{$namespaces}>
              <channel>
                <title>Example Feed</title>
                <link>https://example.com/</link>
                <description>An example feed</description>
                {$itemsXml}
              </channel>
            </rss>
            XML;
    }

    public function testFullTextFeedYieldsFullVerdictAndCapsItemsAtFour(): void
    {
        $items = '';
        for ($i = 1; $i <= 5; ++$i) {
            $items .= <<<XML
                <item>
                  <title>Post {$i}</title>
                  <link>https://example.com/{$i}</link>
                  <guid>https://example.com/{$i}</guid>
                  <description>Short teaser {$i}.</description>
                  <content:encoded><![CDATA[{$this->longParagraph()}]]></content:encoded>
                </item>
                XML;
        }

        $fetcher = $this->fetcherWithBody($this->rss($items));
        $preview = $this->service($fetcher)->preview(self::URL);

        self::assertSame('Example Feed', $preview->title);
        self::assertSame(5, $preview->itemCount);
        self::assertSame('full', $preview->content);
        self::assertCount(4, $preview->items);
        self::assertSame('Post 1', $preview->items[0]->title);
        self::assertGreaterThanOrEqual(600, $preview->items[0]->textLength);
    }

    public function testSummaryOnlyFeedYieldsSummaryVerdict(): void
    {
        $items = '';
        for ($i = 1; $i <= 3; ++$i) {
            $items .= <<<XML
                <item>
                  <title>Post {$i}</title>
                  <link>https://example.com/{$i}</link>
                  <guid>https://example.com/{$i}</guid>
                  <description>Just a short description for post {$i}.</description>
                </item>
                XML;
        }

        $fetcher = $this->fetcherWithBody($this->rss($items));
        $preview = $this->service($fetcher)->preview(self::URL);

        self::assertSame('summary', $preview->content);
        self::assertSame(3, $preview->itemCount);
    }

    public function testTitlesOnlyFeedYieldsTitleOnlyVerdict(): void
    {
        $items = '';
        for ($i = 1; $i <= 3; ++$i) {
            $items .= <<<XML
                <item>
                  <title>Post {$i}</title>
                  <link>https://example.com/{$i}</link>
                  <guid>https://example.com/{$i}</guid>
                </item>
                XML;
        }

        $fetcher = $this->fetcherWithBody($this->rss($items));
        $preview = $this->service($fetcher)->preview(self::URL);

        self::assertSame('title-only', $preview->content);
        self::assertSame(0, $preview->items[0]->textLength);
        self::assertSame('', $preview->items[0]->snippet);
        self::assertFalse($preview->items[0]->hasImage);
    }

    public function testEmptyButTitledFeedYieldsTitleOnlyVerdict(): void
    {
        // A channel with a title but no items parses fine; the verdict must fall
        // back to 'title-only' rather than the richest tier.
        $fetcher = $this->fetcherWithBody($this->rss(''));
        $preview = $this->service($fetcher)->preview(self::URL);

        self::assertSame(0, $preview->itemCount);
        self::assertSame('title-only', $preview->content);
        self::assertFalse($preview->hasImages);
        self::assertSame([], $preview->items);
    }

    public function testItemWithMediaImageMarksHasImages(): void
    {
        $items = <<<'XML'
            <item>
              <title>With image</title>
              <link>https://example.com/1</link>
              <guid>https://example.com/1</guid>
              <description>Has a picture.</description>
              <media:content url="https://example.com/pic.jpg" medium="image" />
            </item>
            <item>
              <title>Without image</title>
              <link>https://example.com/2</link>
              <guid>https://example.com/2</guid>
              <description>No picture here.</description>
            </item>
            XML;

        $xml = $this->rss($items, ' xmlns:media="http://search.yahoo.com/mrss/"');
        $fetcher = $this->fetcherWithBody($xml);
        $preview = $this->service($fetcher)->preview(self::URL);

        self::assertTrue($preview->hasImages);
        self::assertTrue($preview->items[0]->hasImage);
        self::assertFalse($preview->items[1]->hasImage);
    }

    public function testSnippetIsTruncatedOnWordBoundaryForLongItemsAndUntouchedForShortOnes(): void
    {
        $longText = str_repeat('word ', 80); // 400 chars, well over the 200-char snippet limit
        $items = <<<XML
            <item>
              <title>Long</title>
              <link>https://example.com/long</link>
              <guid>https://example.com/long</guid>
              <description>{$longText}</description>
            </item>
            <item>
              <title>Short</title>
              <link>https://example.com/short</link>
              <guid>https://example.com/short</guid>
              <description>A short teaser.</description>
            </item>
            XML;

        $fetcher = $this->fetcherWithBody($this->rss($items));
        $preview = $this->service($fetcher)->preview(self::URL);

        $longSnippet = $preview->items[0]->snippet;
        self::assertStringEndsWith('…', $longSnippet);
        self::assertLessThanOrEqual(201, mb_strlen($longSnippet));

        $shortSnippet = $preview->items[1]->snippet;
        self::assertSame('A short teaser.', $shortSnippet);
        self::assertStringEndsNotWith('…', $shortSnippet);
    }

    public function testFetchFailureBecomesFeedPreviewException(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow(self::URL, new FeedUnreachableException('blocked'));

        $this->expectException(FeedPreviewException::class);
        $this->service($fetcher)->preview(self::URL);
    }

    public function testUnparseableBodyBecomesFeedPreviewException(): void
    {
        $fetcher = $this->fetcherWithBody('<html>nope');

        $this->expectException(FeedPreviewException::class);
        $this->service($fetcher)->preview(self::URL);
    }

    public function testEmptyBodyBecomesFeedPreviewException(): void
    {
        $fetcher = $this->fetcherWithBody('   ');

        $this->expectException(FeedPreviewException::class);
        $this->service($fetcher)->preview(self::URL);
    }
}
