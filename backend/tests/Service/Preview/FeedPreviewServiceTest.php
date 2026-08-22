<?php

declare(strict_types=1);

namespace App\Tests\Service\Preview;

use App\Entity\User;
use App\Enum\SourceFormat;
use App\Exception\FeedPreviewException;
use App\Service\Discovery\Exception\ScrapingDisabledException;
use App\Service\Discovery\ScrapeFallbackPolicy;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\FeedParser;
use App\Service\Parser\WordPressJsonParser;
use App\Service\Preview\FeedPreviewService;
use App\Service\Scraper\HtmlItemExtractor;
use App\Tests\Service\Scraper\ScrapedFixtures;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FeedPreviewServiceTest extends KernelTestCase
{
    use ScrapedFixtures;

    private const URL = 'https://example.com/feed';

    private function service(StubFeedFetcher $fetcher): FeedPreviewService
    {
        $parser = self::getContainer()->get(FeedParser::class);
        self::assertInstanceOf(FeedParser::class, $parser);
        $extractor = self::getContainer()->get(HtmlItemExtractor::class);
        self::assertInstanceOf(HtmlItemExtractor::class, $extractor);

        return new FeedPreviewService(
            $fetcher,
            $parser,
            $extractor,
            new ScrapeFallbackPolicy(),
            new WordPressJsonParser(),
        );
    }

    /** These tests never touch the database, so a User is built inline rather than through a factory. */
    private function user(): User
    {
        return new User('preview@example.com', new \DateTimeImmutable('2026-08-02 10:00:00'));
    }

    private function userWithScrapingEnabled(): User
    {
        $user = new User('preview-scraper@example.com', new \DateTimeImmutable('2026-08-02 10:00:00'));
        $user->getPreferences()->setScrapeFallbackEnabled(true);

        return $user;
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
        // @lang TEXT: the heredoc body is indented, so the XML PhpStorm injects
        // starts with whitespace and it wrongly flags the declaration. The
        // closing marker strips that indentation before the parser sees it.
        return /** @lang TEXT */ <<<XML
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

    public function testFullTextFeedYieldsFullVerdictAndCapsItemsAtEight(): void
    {
        $items = '';
        for ($i = 1; $i <= 9; ++$i) {
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
        $preview = $this->service($fetcher)->preview($this->user(), self::URL);

        self::assertSame('Example Feed', $preview->title);
        self::assertSame(9, $preview->itemCount);
        self::assertSame('full', $preview->content);
        self::assertCount(8, $preview->items);
        self::assertSame('Post 1', $preview->items[0]->title);
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
        $preview = $this->service($fetcher)->preview($this->user(), self::URL);

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
        $preview = $this->service($fetcher)->preview($this->user(), self::URL);

        self::assertSame('title-only', $preview->content);
        self::assertNull($preview->items[0]->summary);
        self::assertNull($preview->items[0]->imageUrl);
    }

    public function testEmptyButTitledFeedYieldsTitleOnlyVerdict(): void
    {
        // A channel with a title but no items parses fine; the verdict must fall
        // back to 'title-only' rather than the richest tier.
        $fetcher = $this->fetcherWithBody($this->rss(''));
        $preview = $this->service($fetcher)->preview($this->user(), self::URL);

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
              <media:content url="https://img.example/a.jpg" medium="image" width="800" height="600" />
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
        $preview = $this->service($fetcher)->preview($this->user(), self::URL);

        self::assertTrue($preview->hasImages);
        self::assertSame('https://img.example/a.jpg', $preview->items[0]->imageUrl);
        self::assertSame(800, $preview->items[0]->imageWidth);
        self::assertSame(600, $preview->items[0]->imageHeight);
        self::assertNull($preview->items[1]->imageUrl);
    }

    public function testHttpImageIsDroppedFromPreviewItem(): void
    {
        $items = <<<'XML'
            <item>
              <title>With image</title>
              <link>https://example.com/1</link>
              <guid>https://example.com/1</guid>
              <description>Has a picture.</description>
              <media:content url="http://img.example/a.jpg" medium="image" width="800" height="600" />
            </item>
            XML;

        $xml = $this->rss($items, ' xmlns:media="http://search.yahoo.com/mrss/"');
        $fetcher = $this->fetcherWithBody($xml);
        $preview = $this->service($fetcher)->preview($this->user(), self::URL);

        self::assertNull($preview->items[0]->imageUrl);
        self::assertNull($preview->items[0]->imageWidth);
        self::assertNull($preview->items[0]->imageHeight);
        // The only image on offer is http-only and gets dropped, so the feed as a
        // whole must not claim to have images either.
        self::assertFalse($preview->hasImages);
    }

    public function testItemSummaryPrefersTheFeedsOwnSummaryOverContentHtml(): void
    {
        // summary and contentHtml carry distinct, recognizable text so the assertion
        // proves the precedence rather than merely tolerating either field: item()
        // must prefer the feed's own summary/teaser, not fall back to the full body.
        $items = <<<XML
            <item>
              <title>Precedence check</title>
              <link>https://example.com/precedence</link>
              <guid>https://example.com/precedence</guid>
              <description>TEASER-FROM-SUMMARY</description>
              <content:encoded><![CDATA[<p>BODY-FROM-CONTENT</p>]]></content:encoded>
            </item>
            XML;

        $fetcher = $this->fetcherWithBody($this->rss($items));
        $preview = $this->service($fetcher)->preview($this->user(), self::URL);

        $summary = $preview->items[0]->summary;
        self::assertNotNull($summary);
        self::assertStringContainsString('TEASER-FROM-SUMMARY', $summary);
        self::assertStringNotContainsString('BODY-FROM-CONTENT', $summary);
    }

    public function testSummaryIsTruncatedForLongItemsAndUntouchedForShortOnes(): void
    {
        // EntrySnippet::from caps a body at 500 characters (its own MAX_LENGTH);
        // this only checks that FeedPreviewService::item() delegates to it
        // rather than reimplementing its own truncation, so 1000 chars of
        // filler is comfortably over that cap.
        $longText = str_repeat('word ', 200);
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
        $preview = $this->service($fetcher)->preview($this->user(), self::URL);

        $longSnippet = $preview->items[0]->summary;
        self::assertNotNull($longSnippet);
        self::assertSame(500, mb_strlen($longSnippet));

        self::assertSame('A short teaser.', $preview->items[1]->summary);
    }

    public function testFetchFailureBecomesFeedPreviewException(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow(self::URL, new FeedUnreachableException('blocked'));

        $this->expectException(FeedPreviewException::class);
        $this->service($fetcher)->preview($this->user(), self::URL);
    }

    public function testUnparseableBodyBecomesFeedPreviewException(): void
    {
        // @lang TEXT: this truncated body is the input under test — the preview
        // must fail on it — so it stays exactly as written rather than growing a
        // `lang` attribute to satisfy PhpStorm's injected-HTML check.
        $fetcher = $this->fetcherWithBody(/** @lang TEXT */ '<html>nope');

        $this->expectException(FeedPreviewException::class);
        $this->service($fetcher)->preview($this->user(), self::URL);
    }

    public function testEmptyBodyBecomesFeedPreviewException(): void
    {
        $fetcher = $this->fetcherWithBody('   ');

        $this->expectException(FeedPreviewException::class);
        $this->service($fetcher)->preview($this->user(), self::URL);
    }

    /**
     * "That address is not a readable feed." fits a feed-document mismatch;
     * for a scraped preview the extractor already words the actual problem
     * for the user, so its message must survive into the exception instead
     * of being flattened to the generic one.
     */
    public function testScrapedPreviewFailureKeepsTheExtractorsMessage(): void
    {
        $fetcher = $this->fetcherWithBody($this->scrapedFixture('nav-only.html'));

        $this->expectException(FeedPreviewException::class);
        $this->expectExceptionMessage('No article list was detected on the page.');
        $this->service($fetcher)->preview($this->userWithScrapingEnabled(), self::URL, 'scraped');
    }

    public function testAScrapedPreviewIsRefusedWhenTheUserHasScrapingDisabled(): void
    {
        $user = new User('preview-off@example.com', new \DateTimeImmutable('2026-08-02 10:00:00'));

        $this->expectException(ScrapingDisabledException::class);

        $this->service(new StubFeedFetcher())->preview($user, 'https://example.com/blog', SourceFormat::SCRAPED);
    }

    public function testPreviewsAWpJsonCandidateAsFullContent(): void
    {
        $body = '[{"id":1,"link":"https://site.example/p","title":{"rendered":"Post"},'
            . '"content":{"rendered":' . json_encode($this->longParagraph()) . '},'
            . '"date_gmt":"2026-08-20T10:00:00"}]';
        $fetcher = $this->fetcherWithBody($body);

        $preview = $this->service($fetcher)->preview($this->user(), self::URL, SourceFormat::WP_JSON);

        self::assertSame(1, $preview->itemCount);
        self::assertSame('full', $preview->content);
    }
}
