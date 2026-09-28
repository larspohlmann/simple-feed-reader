<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest;

use App\Entity\Feed;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Ingest\FeedIngestContext;
use App\Service\Ingest\IncomingEntry;
use App\Service\Ingest\IngestedEntryFactory;
use App\Service\Parser\ParsedEntry;
use App\Service\Sanitize\EntrySanitizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class IngestedEntryFactoryTest extends TestCase
{
    public function testBuildsTheRowFromTheItemCutToItsColumns(): void
    {
        $feed = new Feed('https://example.com/feed');
        $parsed = new ParsedEntry(
            guid: 'g-1',
            url: 'https://example.com/' . str_repeat('u', 3000),
            title: 'T' . str_repeat('t', 2000),
            author: 'A' . str_repeat('a', 500),
            summary: '<p>A &amp; B summary</p>',
            contentHtml: '<p>Body</p><script>evil()</script>',
            publishedAt: new \DateTimeImmutable('2026-09-20 08:00:00'),
        );

        $entry = $this->factory()->create(
            $feed,
            new IncomingEntry($parsed, hash('sha256', 'g-1'), 'url-hash'),
            self::context(),
        );

        self::assertSame($feed, $entry->getFeed());
        self::assertSame('g-1', $entry->getGuid());
        self::assertSame('https://example.com/' . str_repeat('u', 2028), $entry->getUrl());
        self::assertSame('url-hash', $entry->getUrlHash());
        self::assertSame('T' . str_repeat('t', 1023), $entry->getTitle());
        self::assertSame('A' . str_repeat('a', 254), $entry->getAuthor());
        self::assertSame('A & B summary', $entry->getSummary());
        self::assertStringContainsString('<p>Body</p>', (string) $entry->getContentHtml());
        self::assertStringNotContainsString('script', (string) $entry->getContentHtml());
        self::assertSame('2026-09-20 08:00:00', $entry->getPublishedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-21 12:00:00', $entry->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-20 08:00:00', $entry->getEffectiveDate()->format('Y-m-d H:i:s'));
    }

    public function testAnItemWithoutUrlOrAuthorKeepsBothNullAndSummarisesItsBody(): void
    {
        $parsed = new ParsedEntry('g-2', null, 'Title', null, null, '<p>Only a body</p>', null);

        $entry = $this->factory()->create(
            new Feed('https://example.com/feed'),
            new IncomingEntry($parsed, hash('sha256', 'g-2'), null),
            self::context(),
        );

        self::assertNull($entry->getUrl());
        self::assertNull($entry->getUrlHash());
        self::assertNull($entry->getAuthor());
        self::assertSame('Only a body', $entry->getSummary());
        self::assertSame('2026-09-21 12:00:00', $entry->getEffectiveDate()->format('Y-m-d H:i:s'));
    }

    public function testMultibyteColumnsAreCutByCharactersNotBytes(): void
    {
        $parsed = new ParsedEntry(
            guid: 'g-3',
            url: 'https://example.com/' . str_repeat('ä', 3000),
            title: str_repeat('é', 2000),
            author: str_repeat('ü', 500),
            summary: null,
            contentHtml: null,
            publishedAt: null,
        );

        $entry = $this->factory()->create(
            new Feed('https://example.com/feed'),
            new IncomingEntry($parsed, hash('sha256', 'g-3'), 'url-hash'),
            self::context(),
        );

        self::assertSame('https://example.com/' . str_repeat('ä', 2028), $entry->getUrl());
        self::assertSame(str_repeat('é', 1024), $entry->getTitle());
        self::assertSame(str_repeat('ü', 255), $entry->getAuthor());
    }

    private function factory(): IngestedEntryFactory
    {
        return new IngestedEntryFactory(
            new EntrySanitizer(),
            new EntryImageWriter(new NaiveUtcClock(new MockClock('2026-09-21 12:00:00', 'UTC'))),
        );
    }

    private static function context(): FeedIngestContext
    {
        return new FeedIngestContext(new \DateTimeImmutable('2026-09-21 12:00:00'), null);
    }
}
