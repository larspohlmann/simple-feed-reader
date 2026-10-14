<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\QuotedPostContent;
use App\Service\LinkCard\ShareTargetReader;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\StubPageCardReader;
use App\Tests\Support\StubQuotedPostSource;
use PHPUnit\Framework\TestCase;

final class ShareTargetReaderTest extends TestCase
{
    private const string QUOTED = 'https://m.example/@b/9';
    private const string PAGE = 'https://news.example/a';

    private StubPageCardReader $pages;

    protected function setUp(): void
    {
        $this->pages = new StubPageCardReader();
    }

    public function testTheQuotedPostIsReadAsAPostAndNotAsAPage(): void
    {
        $source = new StubQuotedPostSource([self::QUOTED => self::quote()]);

        $cards = $this->reader($source)->cardsFor(
            self::entry(self::bodyQuoting(self::QUOTED)),
            [],
            [self::QUOTED, self::PAGE],
        );

        self::assertSame(self::QUOTED, $cards->quoted?->address);
        self::assertSame([self::PAGE], $this->pages->readUrls());
    }

    public function testAQuoteTheSourceCannotReadFallsBackToALinkCard(): void
    {
        $source = new StubQuotedPostSource();

        $cards = $this->reader($source)->cardsFor(
            self::entry(self::bodyQuoting(self::QUOTED)),
            [],
            [self::QUOTED, self::PAGE],
        );

        self::assertNull($cards->quoted);
        self::assertSame([self::QUOTED, self::PAGE], $this->pages->readUrls());
    }

    public function testAQuoteTheSourceCannotReadIsLoggedWithItsAddress(): void
    {
        $logger = new RecordingLogger();
        $reader = new ShareTargetReader($this->pages, new StubQuotedPostSource(), $logger);

        $reader->cardsFor(self::entry(self::bodyQuoting(self::QUOTED)), [], [self::QUOTED]);

        self::assertCount(1, $logger->records);
        self::assertSame(self::QUOTED, $logger->records[0]['context']['url']);
    }

    public function testAQuoteTargetAlreadyCardedIsNotAskedFor(): void
    {
        $source = new StubQuotedPostSource([self::QUOTED => self::quote()]);

        $cards = $this->reader($source)->cardsFor(self::entry(self::bodyQuoting(self::QUOTED)), [], [self::PAGE]);

        self::assertNull($cards->quoted);
        self::assertSame([], $source->asked);
    }

    public function testABodyWithoutAQuoteLineAsksNoSource(): void
    {
        $source = new StubQuotedPostSource([self::QUOTED => self::quote()]);

        $cards = $this->reader($source)->cardsFor(
            self::entry('<p>Wow <a href="' . self::QUOTED . '">there</a></p>'),
            [],
            [self::QUOTED],
        );

        self::assertNull($cards->quoted);
        self::assertSame([], $source->asked);
    }

    private function reader(StubQuotedPostSource $source): ShareTargetReader
    {
        return new ShareTargetReader($this->pages, $source, new RecordingLogger());
    }

    private static function bodyQuoting(string $target): string
    {
        return '<p>Wow</p><p>RE: <a href="' . $target . '">…</a></p><p><a href="' . self::PAGE . '">a</a></p>';
    }

    private static function entry(string $contentHtml): Entry
    {
        $now = new \DateTimeImmutable('2026-10-11 12:00:00');
        $entry = new Entry(new Feed('https://m.example/@b.rss'), 'guid-1', null, 'Post', $now, $now);
        $entry->setContentHtml($contentHtml);

        return $entry;
    }

    private static function quote(): QuotedPostContent
    {
        return new QuotedPostContent(self::QUOTED, 'Bea', 'bea@m.example', null, null, 'Words', [], null);
    }
}
