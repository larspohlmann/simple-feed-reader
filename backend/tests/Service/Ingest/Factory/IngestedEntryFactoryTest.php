<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\Factory;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Ingest\Factory\IngestedEntryFactory;
use App\Service\Ingest\Model\IncomingEntryModel;
use App\Service\Ingest\Pass\FeedIngestContext;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use PHPUnit\Framework\TestCase;

final class IngestedEntryFactoryTest extends TestCase
{
    public function testBuildsTheRowFromTheItemCutToItsColumns(): void
    {
        $feed = new Feed('https://example.com/feed');
        $parsed = new ParsedEntryModel(
            guid: 'g-1',
            url: 'https://example.com/' . str_repeat('u', 3000),
            headline: Headline::fromFeed('T' . str_repeat('t', 2000)),
            author: 'A' . str_repeat('a', 500),
            summary: '<p>A &amp; B summary</p>',
            contentHtml: '<p>Body</p><script>evil()</script>',
            publishedAt: new \DateTimeImmutable('2026-09-20 08:00:00'),
        );

        $entry = $this->factory()->create(
            $feed,
            new IncomingEntryModel($parsed, hash('sha256', 'g-1'), 'url-hash'),
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
        $title = Headline::fromFeed('Title');
        $parsed = new ParsedEntryModel('g-2', null, $title, null, null, '<p>Only a body</p>', null);

        $entry = $this->factory()->create(
            new Feed('https://example.com/feed'),
            new IncomingEntryModel($parsed, hash('sha256', 'g-2'), null),
            self::context(),
        );

        self::assertNull($entry->getUrl());
        self::assertNull($entry->getUrlHash());
        self::assertNull($entry->getAuthor());
        self::assertSame('Only a body', $entry->getSummary());
        self::assertSame('2026-09-21 12:00:00', $entry->getEffectiveDate()->format('Y-m-d H:i:s'));
    }

    public function testAnItemWhoseTitleWasDerivedMarksTheEntry(): void
    {
        $parsed = new ParsedEntryModel(
            'g-3',
            null,
            Headline::derived('Derived'),
            null,
            null,
            '<p>Derived. Rest</p>',
            null,
        );

        $entry = $this->factory()->create(
            new Feed('https://example.com/feed'),
            new IncomingEntryModel($parsed, hash('sha256', 'g-3'), null),
            self::context(),
        );

        self::assertTrue($entry->isTitleDerived());
    }

    public function testAnItemWithAFeedTitleLeavesTheEntryUnmarked(): void
    {
        $parsed = new ParsedEntryModel('g-4', null, Headline::fromFeed('Given'), null, null, '<p>Body</p>', null);

        $entry = $this->factory()->create(
            new Feed('https://example.com/feed'),
            new IncomingEntryModel($parsed, hash('sha256', 'g-4'), null),
            self::context(),
        );

        self::assertFalse($entry->isTitleDerived());
    }

    public function testMultibyteColumnsAreCutByCharactersNotBytes(): void
    {
        $parsed = new ParsedEntryModel(
            guid: 'g-3',
            url: 'https://example.com/' . str_repeat('ä', 3000),
            headline: Headline::fromFeed(str_repeat('é', 2000)),
            author: str_repeat('ü', 500),
            summary: null,
            contentHtml: null,
            publishedAt: null,
        );

        $entry = $this->factory()->create(
            new Feed('https://example.com/feed'),
            new IncomingEntryModel($parsed, hash('sha256', 'g-3'), 'url-hash'),
            self::context(),
        );

        self::assertSame('https://example.com/' . str_repeat('ä', 2028), $entry->getUrl());
        self::assertSame(str_repeat('é', 1024), $entry->getTitle());
        self::assertSame(str_repeat('ü', 255), $entry->getAuthor());
    }

    public function testStoresAnAbsoluteHttpAuthorUrl(): void
    {
        $entry = $this->createWithAuthorUrl('https://bsky.app/profile/politico.eu');

        self::assertSame('https://bsky.app/profile/politico.eu', $entry->getAuthorUrl());
    }

    public function testNeverStoresAnAuthorUrlThatIsNotHttp(): void
    {
        self::assertNull($this->createWithAuthorUrl('javascript:alert(1)')->getAuthorUrl());
    }

    public function testStoresAnAbsoluteHttpAuthorAvatarUrl(): void
    {
        $entry = $this->createWithAuthorUrl('https://bsky.app/profile/a', 'https://cdn.example/a.jpg');

        self::assertSame('https://cdn.example/a.jpg', $entry->getAuthorAvatarUrl());
    }

    public function testNeverStoresAnAuthorAvatarUrlThatIsNotHttp(): void
    {
        $entry = $this->createWithAuthorUrl('https://bsky.app/profile/a', 'javascript:alert(1)');

        self::assertNull($entry->getAuthorAvatarUrl());
    }

    public function testAnAuthorUrlLongerThanItsColumnIsDroppedNotCut(): void
    {
        $tooLong = 'https://example.com/' . str_repeat('a', 2029);
        self::assertSame(2049, \strlen($tooLong));

        $entry = $this->createWithAuthorUrl($tooLong, $tooLong);

        self::assertNull($entry->getAuthorUrl());
        self::assertNull($entry->getAuthorAvatarUrl());
    }

    public function testAnAuthorUrlThatFillsItsColumnIsKept(): void
    {
        $fits = 'https://example.com/' . str_repeat('a', 2028);

        self::assertSame($fits, $this->createWithAuthorUrl($fits)->getAuthorUrl());
    }

    private function createWithAuthorUrl(string $authorUrl, ?string $authorAvatarUrl = null): Entry
    {
        $parsed = new ParsedEntryModel(
            guid: 'g-4',
            url: 'https://example.com/a',
            headline: Headline::fromFeed('Post'),
            author: 'POLITICO Europe',
            summary: null,
            contentHtml: null,
            publishedAt: null,
            authorUrl: $authorUrl,
            authorAvatarUrl: $authorAvatarUrl,
        );

        return $this->factory()->create(
            new Feed('https://example.com/feed'),
            new IncomingEntryModel($parsed, hash('sha256', 'g-4'), 'url-hash'),
            self::context(),
        );
    }

    private function factory(): IngestedEntryFactory
    {
        return new IngestedEntryFactory(
            new EntrySanitizer(new TrailingBlankRemover()),
            new EntryImageWriter(),
        );
    }

    private static function context(): FeedIngestContext
    {
        return new FeedIngestContext(new \DateTimeImmutable('2026-09-21 12:00:00'), null);
    }
}
