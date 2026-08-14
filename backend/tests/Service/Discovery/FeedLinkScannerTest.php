<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\FeedLinkScanner;
use PHPUnit\Framework\TestCase;

final class FeedLinkScannerTest extends TestCase
{
    private FeedLinkScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new FeedLinkScanner();
    }

    /** @return list<string> */
    private function urls(string $html, string $baseUrl = 'https://example.com/blog/'): array
    {
        return array_map(
            static fn ($candidate): string => $candidate->url,
            $this->scanner->scan($html, $baseUrl),
        );
    }

    public function testItReadsTheAdvertisedFeedsAndTheirDialects(): void
    {
        // @lang TEXT: the hrefs are the subject of the test, not files to resolve.
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><head>
              <link rel="alternate" type="application/rss+xml" title="Main" href="/rss.xml">
              <link rel="alternate" type="application/atom+xml" href="https://cdn.example.com/atom">
              <link rel="stylesheet" href="/style.css">
            </head><body><a href="/feed">RSS</a></body></html>
            HTML;

        $candidates = $this->scanner->scan($html, 'https://example.com/blog/');

        self::assertCount(2, $candidates);
        self::assertSame('https://example.com/rss.xml', $candidates[0]->url);
        self::assertSame('Main', $candidates[0]->title);
        self::assertSame('rss', $candidates[0]->format);
        self::assertSame('https://cdn.example.com/atom', $candidates[1]->url);
        self::assertSame('atom', $candidates[1]->format);
    }

    public function testItFindsTheFooterIconOfAPageThatAdvertisesNothing(): void
    {
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><head><title>A blog</title></head><body>
              <a href="/about">About</a>
              <a href="/feed" title="RSS feed"><img src="/rss.png" alt=""></a>
            </body></html>
            HTML;

        $candidates = $this->scanner->scan($html, 'https://example.com/blog/');

        self::assertCount(1, $candidates);
        self::assertSame('https://example.com/feed', $candidates[0]->url);
        self::assertSame('RSS feed', $candidates[0]->title);
        // Nothing has parsed the document yet, so the dialect is still open.
        self::assertSame('feed', $candidates[0]->format);
    }

    public function testItRecognisesFeedShapedAddresses(): void
    {
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><body>
              <a href="/blog/rss/">1</a>
              <a href="/index.atom">2</a>
              <a href="/feed.xml">3</a>
              <a href="/?feed=rss2">4</a>
            </body></html>
            HTML;

        self::assertSame([
            'https://example.com/blog/rss/',
            'https://example.com/index.atom',
            'https://example.com/feed.xml',
            'https://example.com/?feed=rss2',
        ], $this->urls($html));
    }

    public function testItIgnoresLinksThatMerelyContainAFeedWord(): void
    {
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><body>
              <a href="/feedback">Feedback form</a>
              <a href="/sitemap.xml">Sitemap</a>
              <a href="/newsfeeds-explained">What is a newsfeed?</a>
              <a href="mailto:hi@example.com">Mail</a>
              <a href="#main">Skip</a>
            </body></html>
            HTML;

        self::assertSame([], $this->urls($html));
    }

    public function testItAcceptsAnAlternateLinkWithAVaguerType(): void
    {
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><head>
              <link rel="alternate" type="text/xml" href="/updates">
            </head><body></body></html>
            HTML;

        self::assertSame(['https://example.com/updates'], $this->urls($html));
    }

    public function testItNeverOffersThePageItselfOrTheSameFeedTwice(): void
    {
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><body>
              <a href="https://example.com/blog/">RSS</a>
              <a href="/feed">Feed</a>
              <a href="/feed">Feed</a>
            </body></html>
            HTML;

        self::assertSame(['https://example.com/feed'], $this->urls($html));
    }

    public function testItCapsTheGuesses(): void
    {
        $links = '';
        foreach (range(1, 9) as $index) {
            $links .= sprintf('<a href="/feed%d.xml">%d</a>', $index, $index);
        }

        self::assertCount(5, $this->urls('<!doctype html><html><body>' . $links . '</body></html>'));
    }

    public function testItNeverOffersAPseudoSchemeActionEvenWhenItCallsItselfAFeed(): void
    {
        // Resolving a pseudo scheme against the page rather than rejecting it
        // yields https://example.com/blog/javascript:… — a candidate shaped
        // like a URL that nothing can ever subscribe to.
        $html = /** @lang TEXT */ <<<'HTML'
            <!doctype html><html><body>
              <a href="javascript:openFeedDialog()">RSS</a>
              <a href="mailto:feed@example.com">Atom feed by mail</a>
            </body></html>
            HTML;

        self::assertSame([], $this->urls($html));
    }

    public function testItReadsNothingFromAnEmptyPage(): void
    {
        self::assertSame([], $this->urls('   '));
    }
}
