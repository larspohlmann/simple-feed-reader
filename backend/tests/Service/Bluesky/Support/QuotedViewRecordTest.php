<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky\Support;

use App\Entity\EntryMedium;
use App\Entity\QuotedPostReference;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\QuotedViewRecord;
use App\Tests\Support\Bluesky;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuotedViewRecordTest extends TestCase
{
    private const string AUTHOR = 'did:plc:quoted';

    public function testTheRealQuoteOfAVideoKeepsItsTextLineBreaksAndPlayableVideo(): void
    {
        $quote = QuotedViewRecord::of(Bluesky::post('quote-video')->node('embed')->node('record'));

        self::assertNotNull($quote);
        self::assertStringStartsWith('https://bsky.app/profile/', $quote->url);
        self::assertSame('video', $quote->media[0]->kind ?? null);
        self::assertStringEndsWith('playlist.m3u8', $quote->media[0]->url ?? '');
        self::assertNotNull($quote->media[0]->previewImageUrl ?? null);
        self::assertNotNull($quote->postedAt);
    }

    public function testAQuotedPostNamesItsAuthorTimeAndFullText(): void
    {
        $text = str_repeat('x', 280) . "\n\n" . str_repeat('y', 19);

        $quote = QuotedViewRecord::of(self::viewRecord(['text' => $text]));

        self::assertSame(
            ['https://bsky.app/profile/' . self::AUTHOR . '/post/3kq', 'Quoted Person', 'quoted.bsky.social',
                'https://cdn.bsky.app/img/avatar/plain/q.jpg', '2026-10-13 21:07:00', $text],
            [$quote?->url, $quote?->authorName, $quote?->authorHandle, $quote?->authorAvatarUrl,
                $quote?->postedAt?->format('Y-m-d H:i:s'), $quote?->text],
        );
    }

    public function testTheIndexTimeIsTheFallbackWhenThePostHasNoCreationTime(): void
    {
        $quote = QuotedViewRecord::of(self::viewRecord(['createdAt' => null]));

        self::assertSame('2026-10-13 21:07:01', $quote?->postedAt?->format('Y-m-d H:i:s'));
    }

    public function testAnAuthorWithoutADisplayNameIsNamedByHandle(): void
    {
        self::assertSame(
            'quoted.bsky.social',
            QuotedViewRecord::of(self::viewRecord(author: ['handle' => 'quoted.bsky.social']))?->authorName,
        );
    }

    public function testImagesKeepTheirFullSizeAndRatio(): void
    {
        $quote = QuotedViewRecord::of(self::viewRecord(embed: [
            '$type' => 'app.bsky.embed.images#view',
            'images' => [
                [
                    'fullsize' => 'https://cdn.bsky.app/full/1.jpg',
                    'thumb' => 'https://cdn.bsky.app/thumb/1.jpg',
                    'aspectRatio' => ['width' => 1200, 'height' => 675],
                ],
                ['fullsize' => 'https://cdn.bsky.app/full/2.jpg', 'aspectRatio' => ['width' => 800, 'height' => 1000]],
            ],
        ]));

        self::assertEquals([
            new EntryMedium('https://cdn.bsky.app/full/1.jpg', 'image', 1200, 675),
            new EntryMedium('https://cdn.bsky.app/full/2.jpg', 'image', 800, 1000),
        ], $quote?->media);
    }

    public function testAQuotedRecordWithMediaGivesItsMediaAndItsOwnQuoteAsTheNestedLine(): void
    {
        $quote = QuotedViewRecord::of(self::viewRecord(embed: [
            '$type' => 'app.bsky.embed.recordWithMedia#view',
            'media' => [
                '$type' => 'app.bsky.embed.images#view',
                'images' => [['fullsize' => 'https://cdn.bsky.app/full/3.jpg']],
            ],
            'record' => ['record' => self::nested()],
        ]));

        self::assertEquals([new EntryMedium('https://cdn.bsky.app/full/3.jpg', 'image')], $quote?->media);
        self::assertEquals(
            new QuotedPostReference('https://bsky.app/profile/did:plc:nested/post/9zz', 'nested.bsky.social'),
            $quote?->nestedQuote,
        );
    }

    public function testAQuotedRecordGivesNoMediaAndTheNestedLine(): void
    {
        $quote = QuotedViewRecord::of(
            self::viewRecord(embed: ['$type' => 'app.bsky.embed.record#view', 'record' => self::nested()]),
        );

        self::assertSame([], $quote?->media);
        self::assertSame('nested.bsky.social', $quote->nestedQuote?->handle);
    }

    public function testAQuotedLinkCardGivesNoMedia(): void
    {
        $quote = QuotedViewRecord::of(self::viewRecord(embed: [
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'https://example.com/', 'title' => 'T', 'thumb' => 'https://cdn.bsky.app/t.jpg'],
        ]));

        self::assertSame([], $quote?->media);
        self::assertNull($quote->nestedQuote);
    }

    public function testAQuotedGifIsItsAnimatedImage(): void
    {
        $quote = QuotedViewRecord::of(self::viewRecord(embed: [
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'https://media.tenor.com/a/clap.gif?hh=237&ww=316', 'title' => 'Clap'],
        ]));

        self::assertEquals(
            [new EntryMedium('https://media.tenor.com/a/clap.gif?hh=237&ww=316', 'image', 316, 237)],
            $quote?->media,
        );
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unavailable(): iterable
    {
        $uri = 'at://did:plc:q/app.bsky.feed.post/3kq';
        yield 'not found' => [['$type' => 'app.bsky.embed.record#viewNotFound', 'uri' => $uri, 'notFound' => true]];
        yield 'blocked' => [['$type' => 'app.bsky.embed.record#viewBlocked', 'uri' => $uri, 'blocked' => true]];
        yield 'detached' => [['$type' => 'app.bsky.embed.record#viewDetached', 'uri' => $uri, 'detached' => true]];
        yield 'a blocked view that still carries a post' => [
            self::viewRecordArray(type: 'app.bsky.embed.record#viewBlocked'),
        ];
        yield 'a list, not a post' => [self::viewRecordArray(valueType: 'app.bsky.graph.list')];
        yield 'an uri that is no post uri' => [self::viewRecordArray(uri: 'at://did:plc:q/app.bsky.graph.list/3kq')];
        yield 'no handle' => [self::viewRecordArray(author: ['displayName' => 'Nameless'])];
    }

    /** @param array<string, mixed> $record */
    #[DataProvider('unavailable')]
    public function testAnUnavailableQuoteIsNone(array $record): void
    {
        self::assertNull(QuotedViewRecord::of(JsonNodeModel::of($record)));
    }

    /**
     * @param array<string, mixed>      $value
     * @param array<string, mixed>|null $embed
     * @param array<string, mixed>      $author
     */
    private static function viewRecord(array $value = [], ?array $embed = null, array $author = []): JsonNodeModel
    {
        return JsonNodeModel::of(self::viewRecordArray(value: $value, embed: $embed, author: $author ?: null));
    }

    /**
     * @param array<string, mixed>      $value
     * @param array<string, mixed>|null $embed
     * @param array<string, mixed>|null $author
     *
     * @return array<string, mixed>
     */
    private static function viewRecordArray(
        array $value = [],
        ?array $embed = null,
        ?array $author = null,
        string $valueType = 'app.bsky.feed.post',
        string $uri = 'at://' . self::AUTHOR . '/app.bsky.feed.post/3kq',
        string $type = 'app.bsky.embed.record#viewRecord',
    ): array {
        return [
            '$type' => $type,
            'uri' => $uri,
            'author' => $author ?? [
                'handle' => 'quoted.bsky.social',
                'displayName' => 'Quoted Person',
                'avatar' => 'https://cdn.bsky.app/img/avatar/plain/q.jpg',
            ],
            'value' => [
                '$type' => $valueType,
                'text' => 'Quoted.',
                'createdAt' => '2026-10-13T23:07:00.000+02:00',
                ...$value,
            ],
            'indexedAt' => '2026-10-13T21:07:01.000Z',
            'embeds' => $embed === null ? [] : [$embed],
        ];
    }

    /** @return array<string, mixed> */
    private static function nested(): array
    {
        return [
            '$type' => 'app.bsky.embed.record#viewRecord',
            'uri' => 'at://did:plc:nested/app.bsky.feed.post/9zz',
            'author' => ['handle' => 'nested.bsky.social'],
        ];
    }
}
