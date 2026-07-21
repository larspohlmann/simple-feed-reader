<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\AtomParser;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Parser\Rss1Parser;
use App\Service\Parser\Rss2Parser;
use PHPUnit\Framework\TestCase;

final class FeedParserTest extends TestCase
{
    private function parser(): FeedParser
    {
        return new FeedParser(new Rss2Parser(), new AtomParser(), new Rss1Parser());
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/feeds/' . $name);
    }

    public function testParsesRss2Basic(): void
    {
        $feed = $this->parser()->parse($this->fixture('rss2-basic.xml'));

        self::assertSame('Example Tech Blog', $feed->title);
        self::assertSame('https://blog.example.com/', $feed->siteUrl);
        self::assertSame('News from Example', $feed->description);
        self::assertCount(2, $feed->entries);

        $first = $feed->entries[0];
        self::assertSame('tag:blog.example.com,2026:announcement', $first->guid);
        self::assertSame('Big <Announcement> & More', $first->title);
        self::assertSame('https://blog.example.com/announcement', $first->url);
        self::assertSame('Jane Doe', $first->author);
        self::assertSame('Short teaser text.', $first->summary);
        self::assertStringContainsString('<strong>story</strong>', (string) $first->contentHtml);
        self::assertSame('2026-07-20T08:30:00+02:00', $first->publishedAt?->format(DATE_ATOM));

        $second = $feed->entries[1];
        self::assertSame('https://blog.example.com/second', $second->guid);
        self::assertNull($second->summary);
        self::assertStringContainsString('Description-only body', (string) $second->contentHtml);
    }

    public function testMissingGuidFallsBackToHashAndBrokenDateBecomesNull(): void
    {
        $feed = $this->parser()->parse($this->fixture('rss2-no-guid.xml'));

        self::assertCount(2, $feed->entries);
        $first = $feed->entries[0];
        self::assertSame(
            'urn:sfr:' . hash('sha256', 'https://noguid.example.com/post-1|Post without guid'),
            $first->guid,
        );
        self::assertNull($first->publishedAt);
        self::assertNotSame($feed->entries[1]->guid, $first->guid);
    }

    public function testRejectsNonXml(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse('this is { not xml');
    }

    public function testRejectsUnknownRootElement(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse('<?xml version="1.0"?><html><body>nope</body></html>');
    }
}
