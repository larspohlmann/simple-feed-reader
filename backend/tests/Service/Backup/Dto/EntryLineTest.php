<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Entity\EntryChapters;
use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Entity\QuotedPostReference;
use App\Entity\EntryOrigin;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Exception\InvalidBackupException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryLineTest extends TestCase
{
    /** @return array<string, mixed> */
    private function baseLine(): array
    {
        return [
            'feedUrl' => 'https://f/feed', 'guid' => 'g', 'guidHash' => str_repeat('a', 64),
            'url' => 'https://f/one', 'title' => 'One', 'author' => null, 'summary' => null,
            'contentHtml' => null, 'imageUrl' => null, 'imageWidth' => null, 'imageHeight' => null,
            'publishedAt' => null, 'createdAt' => '2026-09-07T00:00:00+00:00',
            'effectiveDate' => '2026-09-07T00:00:00+00:00',
        ];
    }

    public function testReadsMediaAndAttachments(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + [
            'media' => [['url' => 'https://i/lead.jpg', 'kind' => 'image', 'width' => 800]],
            'attachments' => [['url' => 'https://cdn/ep.mp3', 'mimeType' => 'audio/mpeg']],
        ]);

        self::assertSame([['url' => 'https://i/lead.jpg', 'kind' => 'image', 'width' => 800]], $line->media);
        self::assertSame([['url' => 'https://cdn/ep.mp3', 'mimeType' => 'audio/mpeg']], $line->attachments);
    }

    public function testDefaultsToEmptyWhenAnOlderFileOmitsTheKeys(): void
    {
        $line = EntryLine::fromLine($this->baseLine());

        self::assertSame([], $line->media);
        self::assertSame([], $line->attachments);
    }

    public function testAChaptersFileUrlIsKeptForAnEpisodeWithoutChapters(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['chaptersUrl' => 'https://cdn.example/ch.json']);

        self::assertSame('https://cdn.example/ch.json', $line->chaptersUrl);
    }

    public function testAChaptersFileUrlIsDroppedOnceTheEpisodeHasChapters(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + [
            'chapters' => [['startSeconds' => 0, 'title' => 'Intro']],
            'chaptersUrl' => 'https://cdn.example/ch.json',
        ]);

        self::assertSame([['startSeconds' => 0, 'title' => 'Intro']], $line->chapters);
        self::assertNull($line->chaptersUrl);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableChaptersUrls(): iterable
    {
        yield 'not http' => ['javascript:alert(1)'];
        yield 'relative' => ['/ch.json'];
        yield 'too long' => ['https://cdn.example/' . str_repeat('a', EntryChapters::URL_MAX_LENGTH)];
    }

    #[DataProvider('unusableChaptersUrls')]
    public function testAnUnusableChaptersFileUrlIsDropped(string $url): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['chaptersUrl' => $url]);

        self::assertNull($line->chaptersUrl);
    }

    public function testAChaptersFileUrlAtTheLengthLimitIsKept(): void
    {
        $prefix = 'https://cdn.example/';
        $url = $prefix . str_repeat('a', EntryChapters::URL_MAX_LENGTH - \strlen($prefix));

        self::assertSame($url, EntryLine::fromLine($this->baseLine() + ['chaptersUrl' => $url])->chaptersUrl);
    }

    public function testReadsTheOriginOfASavedCopy(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['origin' => [
            'title' => 'Heise',
            'faviconUrl' => 'https://heise.example/favicon.ico',
        ]]);

        self::assertEquals(new EntryOrigin('Heise', 'https://heise.example/favicon.ico'), $line->origin);
    }

    public function testAnOriginFaviconThatIsNotHttpIsDropped(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['origin' => [
            'title' => 'Heise',
            'faviconUrl' => 'javascript:alert(1)',
        ]]);

        self::assertEquals(new EntryOrigin('Heise', null), $line->origin);
    }

    public function testAnOriginTitleOverTheColumnIsClipped(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['origin' => [
            'title' => str_repeat('t', 600),
            'faviconUrl' => null,
        ]]);

        self::assertSame(str_repeat('t', 512), $line->origin?->title);
    }

    public function testAMissingOrNullOriginReadsAsNone(): void
    {
        self::assertNull(EntryLine::fromLine($this->baseLine())->origin);
        self::assertNull(EntryLine::fromLine($this->baseLine() + ['origin' => null])->origin);
    }

    public function testAnOriginThatIsNotAnObjectIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        EntryLine::fromLine($this->baseLine() + ['origin' => 'Heise']);
    }

    public function testAnOlderFilesShareUrlIsIgnored(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['shareKind' => 'link', 'shareUrl' => 'https://f/one']);

        self::assertSame('link', $line->shareKind);
    }

    public function testReadsTheLinkCardsAndDropsOnesNoCardMayHold(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['linkCards' => [
            [
                'url' => 'https://example.com/a',
                'title' => 'A',
                'description' => 'About A',
                'imageUrl' => 'https://example.com/a.jpg',
                'siteName' => 'Example',
            ],
            ['url' => 'javascript:alert(1)', 'title' => 'X', 'siteName' => 'X'],
            ['url' => 'https://example.com/b', 'title' => 'B', 'imageUrl' => 'http://e.com/b.jpg', 'siteName' => 'E'],
        ]]);

        self::assertEquals([
            new LinkCardContent('https://example.com/a', 'A', 'About A', 'https://example.com/a.jpg', 'Example'),
            new LinkCardContent('https://example.com/b', 'B', null, null, 'E'),
        ], $line->linkCards);
    }

    public function testAnOlderFileWithoutLinkCardsReadsAsNone(): void
    {
        self::assertSame([], EntryLine::fromLine($this->baseLine())->linkCards);
    }

    public function testReadsWhetherTheTitleWasDerived(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['titleDerived' => true]);

        self::assertTrue($line->titleDerived);
    }

    public function testAnOlderFileWithoutTitleDerivedReadsAsNotDerived(): void
    {
        self::assertFalse(EntryLine::fromLine($this->baseLine())->titleDerived);
    }

    public function testAConstructedLineIsNotDerivedUnlessSaid(): void
    {
        $moment = new \DateTimeImmutable('2026-09-07T00:00:00+00:00');

        $line = new EntryLine(
            feedUrl: 'https://f/feed',
            guid: 'g',
            guidHash: str_repeat('a', 64),
            url: 'https://f/one',
            title: 'One',
            author: null,
            summary: null,
            contentHtml: null,
            imageUrl: null,
            imageWidth: null,
            imageHeight: null,
            publishedAt: null,
            createdAt: $moment,
            effectiveDate: $moment,
        );

        self::assertFalse($line->titleDerived);
    }

    public function testReadsTheImageRenditions(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + [
            'imageRenditions' => [['url' => 'https://i/lead-600.jpg', 'width' => 600]],
        ]);

        self::assertSame([['url' => 'https://i/lead-600.jpg', 'width' => 600]], $line->imageRenditions);
    }

    public function testAnOlderFileWithoutImageRenditionsReadsAsNone(): void
    {
        self::assertSame([], EntryLine::fromLine($this->baseLine())->imageRenditions);
    }

    public function testReadsTheAuthorsProfileAndPicture(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + [
            'authorUrl' => 'https://www.youtube.com/channel/UC1',
            'authorAvatarUrl' => 'https://cdn.example/a.jpg',
        ]);

        self::assertSame('https://www.youtube.com/channel/UC1', $line->authorUrl);
        self::assertSame('https://cdn.example/a.jpg', $line->authorAvatarUrl);
    }

    public function testAnOlderFileWithoutTheAuthorsProfileReadsAsNone(): void
    {
        $line = EntryLine::fromLine($this->baseLine());

        self::assertNull($line->authorUrl);
        self::assertNull($line->authorAvatarUrl);
    }

    public function testAnAuthorUrlNoEntryMayHoldIsDropped(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + [
            'authorUrl' => 'javascript:alert(1)',
            'authorAvatarUrl' => 'https://cdn.example/' . str_repeat('a', 2029),
        ]);

        self::assertNull($line->authorUrl);
        self::assertNull($line->authorAvatarUrl);
    }

    /** @return array<string, mixed> */
    private static function quoteFields(): array
    {
        return [
            'url' => 'https://bsky.app/profile/q.bsky.social/post/3k',
            'authorName' => 'Quoted Person',
            'authorHandle' => 'q.bsky.social',
            'authorAvatarUrl' => 'https://cdn.example/q.jpg',
            'postedAt' => '2026-10-13T21:07:00+00:00',
            'text' => 'quoted text',
            'media' => [['url' => 'https://cdn.example/1.jpg', 'kind' => 'image', 'width' => 1200, 'height' => 675]],
            'nestedQuote' => ['url' => 'https://bsky.app/profile/n/post/9', 'handle' => 'n.bsky.social'],
        ];
    }

    public function testReadsTheQuotedPost(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['shareKind' => 'quote', 'quote' => self::quoteFields()]);

        self::assertEquals(
            new QuotedPostContent(
                'https://bsky.app/profile/q.bsky.social/post/3k',
                'Quoted Person',
                'q.bsky.social',
                'https://cdn.example/q.jpg',
                new \DateTimeImmutable('2026-10-13T21:07:00+00:00'),
                'quoted text',
                [new EntryMedium('https://cdn.example/1.jpg', 'image', 1200, 675)],
                new QuotedPostReference('https://bsky.app/profile/n/post/9', 'n.bsky.social'),
            ),
            $line->quote,
        );
        self::assertSame('quote', $line->shareKind);
    }

    public function testAnOlderFileWithoutAQuoteReadsAsNone(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['shareKind' => 'link']);

        self::assertNull($line->quote);
        self::assertSame('link', $line->shareKind);
    }

    public function testAQuoteShareWithoutAQuoteRestoresAsALink(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['shareKind' => 'quote']);

        self::assertNull($line->quote);
        self::assertSame('link', $line->shareKind);
    }

    public function testAQuoteUnderANonQuoteShareKindIsDropped(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + ['shareKind' => 'link', 'quote' => self::quoteFields()]);

        self::assertNull($line->quote);
        self::assertSame('link', $line->shareKind);
    }

    public function testAHostileQuoteIsDroppedAndTheShareBecomesALink(): void
    {
        $line = EntryLine::fromLine($this->baseLine() + [
            'shareKind' => 'quote',
            'quote' => ['url' => 'javascript:alert(1)'] + self::quoteFields(),
        ]);

        self::assertNull($line->quote);
        self::assertSame('link', $line->shareKind);
    }

    public function testAQuotesMediaPassTheGateEveryMadeQuotePasses(): void
    {
        $fields = self::quoteFields();
        $fields['media'] = [
            ['url' => 'http://cdn.example/plain.jpg', 'kind' => 'image'],
            ['url' => 'https://cdn.example/clip.mp4', 'kind' => 'video', 'previewImageUrl' => 'javascript:alert(1)'],
            ['kind' => 'image'],
        ];

        $line = EntryLine::fromLine($this->baseLine() + ['shareKind' => 'quote', 'quote' => $fields]);

        self::assertEquals([new EntryMedium('https://cdn.example/clip.mp4', 'video')], $line->quote?->media);
    }

    public function testAQuoteWithoutATimeOrANestedQuoteReadsAsNone(): void
    {
        $fields = ['postedAt' => null, 'nestedQuote' => null, 'media' => []] + self::quoteFields();

        $line = EntryLine::fromLine($this->baseLine() + ['shareKind' => 'quote', 'quote' => $fields]);

        self::assertNull($line->quote?->postedAt);
        self::assertNull($line->quote?->nestedQuote);
        self::assertSame([], $line->quote?->media);
    }
}
