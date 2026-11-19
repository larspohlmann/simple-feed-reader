<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest\Factory;

use App\Entity\DiscussionCounts;
use App\Entity\Entry;
use App\Entity\EntryChapter;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Enum\FeedLanguage;
use App\Enum\SourceFormat;
use App\Service\Foundation\Sanitize\EntrySanitizer;
use App\Service\Foundation\Sanitize\TrailingBlankRemover;
use App\Service\Ingestion\Ingest\EntryImageWriter;
use App\Service\Ingestion\Ingest\Factory\IngestedEntryFactory;
use App\Service\Ingestion\Ingest\Model\IncomingEntryModel;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Parser\Model\ParsedChaptersModel;
use App\Service\Ingestion\Parser\Model\ParsedEntryMediaModel;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedMediaBundleModel;
use App\Tests\Support\LanguageWiring;
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
        self::assertSame('A & B summary', $entry->getText()->getSummary());
        self::assertStringContainsString('<p>Body</p>', (string) $entry->getText()->getContentHtml());
        self::assertStringNotContainsString('script', (string) $entry->getText()->getContentHtml());
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
        self::assertSame('Only a body', $entry->getText()->getSummary());
        self::assertSame('2026-09-21 12:00:00', $entry->getEffectiveDate()->format('Y-m-d H:i:s'));
    }

    public function testStoresTheUrlWithoutTrackingParameters(): void
    {
        self::assertSame(
            'https://example.com/a?id=1',
            $this->entryAt('https://example.com/a?id=1&utm_source=rss#xtor=RSS-3')->getUrl(),
        );
    }

    public function testAUrlWithoutTrackersIsStoredAsGiven(): void
    {
        self::assertSame('HTTPS://Example.com/A?', $this->entryAt('HTTPS://Example.com/A?')->getUrl());
    }

    private function entryAt(string $url): Entry
    {
        $parsed = new ParsedEntryModel('g-3', $url, Headline::fromFeed('Title'), null, null, '<p>Body</p>', null);

        return $this->factory()->create(
            new Feed('https://example.com/feed'),
            new IncomingEntryModel($parsed, hash('sha256', 'g-3'), null),
            self::context(),
        );
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

    public function testStoresTheItemsDeclaredChapters(): void
    {
        $entry = $this->createWithChapters(new ParsedChaptersModel(
            [new EntryChapter(0, 'Intro'), new EntryChapter(60, 'Talk')],
        ));

        self::assertEquals([new EntryChapter(0, 'Intro'), new EntryChapter(60, 'Talk')], $entry->getChapters());
        self::assertNull($entry->getChaptersUrl());
    }

    public function testKeepsALinkedChaptersFileToFetchLater(): void
    {
        $entry = $this->createWithChapters(new ParsedChaptersModel([], 'https://cdn.test/ch.json'));

        self::assertSame('https://cdn.test/ch.json', $entry->getChaptersUrl());
    }

    public function testDropsALinkedChaptersFileThatIsNotHttp(): void
    {
        $entry = $this->createWithChapters(new ParsedChaptersModel([], 'file:///etc/passwd'));

        self::assertNull($entry->getChaptersUrl());
    }

    private function createWithChapters(ParsedChaptersModel $chapters): Entry
    {
        $parsed = new ParsedEntryModel(
            guid: 'g-5',
            url: 'https://example.com/a',
            headline: Headline::fromFeed('Episode'),
            author: null,
            summary: null,
            contentHtml: null,
            publishedAt: null,
            media: new ParsedEntryMediaModel(null, new ParsedMediaBundleModel([], [], $chapters)),
        );

        return $this->factory()->create(
            new Feed('https://example.com/feed'),
            new IncomingEntryModel($parsed, hash('sha256', 'g-5'), 'url-hash'),
            self::context(),
        );
    }

    public function testCarriesTheParsedDiscussionCounts(): void
    {
        $parsed = new ParsedEntryModel(
            guid: 'g-6',
            url: 'https://example.com/a',
            headline: Headline::fromFeed('Post'),
            author: null,
            summary: null,
            contentHtml: null,
            publishedAt: null,
        )->withDiscussionCounts(DiscussionCounts::of(7, 2));

        $entry = $this->factory()->create(
            new Feed('https://example.com/feed'),
            new IncomingEntryModel($parsed, hash('sha256', 'g-6'), 'url-hash'),
            self::context(),
        );

        self::assertSame(2, $entry->getCommentCount());
    }

    public function testASocialTimelineEntryStoresItsGuessedLanguage(): void
    {
        $feed = new Feed('bluesky://timeline');
        $feed->setSourceFormat(SourceFormat::BLUESKY_API);

        $entry = $this->factory()->create($feed, self::incomingGerman(), self::context());

        self::assertSame(FeedLanguage::German, $entry->getText()->getLanguage());
    }

    public function testASocialEntryPrefersTheLanguageItsAuthorStated(): void
    {
        $feed = new Feed('mastodon://timeline');
        $feed->setSourceFormat(SourceFormat::MASTODON_API);
        $parsed = new ParsedEntryModel(
            'g-stated',
            null,
            Headline::derived('…'),
            null,
            null,
            '<p>Der Kanzler</p>',
            null,
            statedLanguage: 'fr',
        );
        $incoming = new IncomingEntryModel($parsed, hash('sha256', 'g'), null);

        $entry = $this->factory()->create($feed, $incoming, self::context());

        self::assertSame(FeedLanguage::French, $entry->getText()->getLanguage());
    }

    public function testAnEntryOfAnOrdinaryFeedStoresNoLanguage(): void
    {
        $feed = new Feed('https://example.com/feed');

        $entry = $this->factory()->create($feed, self::incomingGerman(), self::context());

        self::assertNull($entry->getText()->getLanguage());
    }

    private static function incomingGerman(): IncomingEntryModel
    {
        $parsed = new ParsedEntryModel(
            'g-de',
            null,
            Headline::derived('…'),
            null,
            null,
            '<p>Der Kanzler sagte am Montag, dass die Regierung weitere Hilfen für die Menschen vorbereitet.</p>',
            null,
        );

        return new IncomingEntryModel($parsed, hash('sha256', 'g-de'), null);
    }

    private function factory(): IngestedEntryFactory
    {
        return new IngestedEntryFactory(
            new EntrySanitizer(new TrailingBlankRemover()),
            new EntryImageWriter(),
            LanguageWiring::socialEntryLanguage(),
        );
    }

    private static function context(): FeedIngestContext
    {
        return new FeedIngestContext(new \DateTimeImmutable('2026-09-21 12:00:00'), null);
    }
}
