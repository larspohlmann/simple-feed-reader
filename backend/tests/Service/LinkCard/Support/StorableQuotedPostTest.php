<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard\Support;

use App\Entity\EntryMedium;
use App\Entity\QuotedPostContent;
use App\Entity\QuotedPostReference;
use App\Service\LinkCard\Support\StorableQuotedPost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorableQuotedPostTest extends TestCase
{
    private const string URL = 'https://mastodon.example/@alice/113';

    public function testAQuoteThatFitsIsKeptAsItIs(): void
    {
        $quote = self::quote();

        self::assertEquals($quote, StorableQuotedPost::of($quote));
    }

    /** @return iterable<string, array{QuotedPostContent}> */
    public static function unstorable(): iterable
    {
        yield 'an url that is no web page' => [self::quote(url: 'at://did:plc:q/app.bsky.feed.post/3k')];
        yield 'an url one char too long' => [self::quote(url: 'https://a.example/' . str_repeat('x', 2048 - 17))];
        yield 'a blank handle' => [self::quote(handle: '  ')];
    }

    #[DataProvider('unstorable')]
    public function testAQuoteThatCannotBeStoredIsNone(QuotedPostContent $quote): void
    {
        self::assertNull(StorableQuotedPost::of($quote));
    }

    public function testAnUrlOfExactlyTheLimitIsKept(): void
    {
        $url = 'https://a.example/' . str_repeat('x', 2048 - 18);

        self::assertSame($url, StorableQuotedPost::of(self::quote(url: $url))?->url);
    }

    public function testABlankNameFallsBackToTheHandle(): void
    {
        self::assertSame('alice@mastodon.example', StorableQuotedPost::of(self::quote(name: ' '))?->authorName);
    }

    public function testLongNamesAndHandlesAreCutToTheColumn(): void
    {
        $kept = StorableQuotedPost::of(self::quote(name: str_repeat('n', 300), handle: str_repeat('h', 260)));

        self::assertSame(
            [255, 255],
            [mb_strlen((string) $kept?->authorName), mb_strlen((string) $kept?->authorHandle)],
        );
    }

    public function testAnAvatarThatIsNotHttpsIsDropped(): void
    {
        self::assertNull(StorableQuotedPost::of(self::quote(avatar: 'http://cdn.example/a.jpg'))?->authorAvatarUrl);
    }

    public function testAnAvatarUrlPastTheColumnIsDropped(): void
    {
        $avatar = 'https://cdn.example/' . str_repeat('a', 2048);

        self::assertNull(StorableQuotedPost::of(self::quote(avatar: $avatar))?->authorAvatarUrl);
    }

    public function testTheTextIsClippedAtAWordBoundaryJustUnderThreeThousand(): void
    {
        $kept = (string) StorableQuotedPost::of(self::quote(text: str_repeat('word ', 700)))?->text;

        self::assertLessThanOrEqual(3000, mb_strlen($kept));
        self::assertGreaterThan(2990, mb_strlen($kept));
        self::assertStringEndsWith('word…', $kept);
    }

    public function testATimeWithAnOffsetIsStoredInUtc(): void
    {
        $kept = StorableQuotedPost::of(self::quote(postedAt: new \DateTimeImmutable('2026-10-13T23:07:00+02:00')));

        self::assertSame('2026-10-13 21:07:00 UTC', $kept?->postedAt?->format('Y-m-d H:i:s e'));
    }

    public function testMediaPastFourAndNonHttpsMediaAreDropped(): void
    {
        $media = [
            new EntryMedium('http://cdn.example/0.jpg', 'image'),
            ...array_map(
                static fn (int $index): EntryMedium => new EntryMedium("https://cdn.example/$index.jpg", 'image'),
                range(1, 5),
            ),
        ];

        $kept = StorableQuotedPost::of(self::quote(media: $media));

        self::assertNotNull($kept);
        self::assertSame(
            [
                'https://cdn.example/1.jpg',
                'https://cdn.example/2.jpg',
                'https://cdn.example/3.jpg',
                'https://cdn.example/4.jpg',
            ],
            array_map(static fn (EntryMedium $medium): string => $medium->url, $kept->media),
        );
    }

    public function testAPosterThatIsNotHttpsIsDroppedAndTheMediumKept(): void
    {
        $media = [
            new EntryMedium('https://cdn.example/a.mp4', 'video', 640, 360, 'http://cdn.example/poster.jpg'),
            new EntryMedium('https://cdn.example/b.mp4', 'video', null, null, 'https://cdn.example/poster.jpg'),
            new EntryMedium('https://cdn.example/c.mp4', 'video', null, null, 'javascript:alert(1)'),
        ];

        $kept = StorableQuotedPost::of(self::quote(media: $media));

        self::assertNotNull($kept);
        self::assertEquals(
            [
                new EntryMedium('https://cdn.example/a.mp4', 'video', 640, 360),
                new EntryMedium('https://cdn.example/b.mp4', 'video', null, null, 'https://cdn.example/poster.jpg'),
                new EntryMedium('https://cdn.example/c.mp4', 'video'),
            ],
            $kept->media,
        );
    }

    public function testANestedQuoteThatIsNoWebPageIsDropped(): void
    {
        $nested = new QuotedPostReference('at://did:plc:n/app.bsky.feed.post/9', 'n.bsky.social');

        self::assertNull(StorableQuotedPost::of(self::quote(nested: $nested))?->nestedQuote);
    }

    public function testANestedHandleIsTrimmedAndCutToTheColumn(): void
    {
        $nested = new QuotedPostReference('https://other.example/@b/2', ' ' . str_repeat('h', 260));

        $kept = StorableQuotedPost::of(self::quote(nested: $nested));

        self::assertSame(str_repeat('h', 255), $kept?->nestedQuote?->handle);
    }

    public function testNamesAndHandlesAreCutByCharactersNotBytes(): void
    {
        $nested = new QuotedPostReference('https://other.example/@b/2', str_repeat('é', 260));

        $kept = StorableQuotedPost::of(
            self::quote(name: str_repeat('é', 260), handle: str_repeat('é', 260), nested: $nested),
        );

        $accented = str_repeat('é', 255);
        self::assertSame($accented, $kept?->authorName);
        self::assertSame($accented, $kept->authorHandle);
        self::assertSame($accented, $kept->nestedQuote?->handle);
    }

    public function testTheUrlLimitCountsCharactersNotBytes(): void
    {
        $url = 'https://a.example/' . str_repeat('é', 2048 - 18);

        self::assertSame($url, StorableQuotedPost::of(self::quote(url: $url))?->url);
    }

    public function testABlankNestedHandleIsNone(): void
    {
        $nested = new QuotedPostReference('https://other.example/@b/2', '  ');

        self::assertNull(StorableQuotedPost::of(self::quote(nested: $nested))?->nestedQuote?->handle);
    }

    /** @param list<EntryMedium> $media */
    private static function quote(
        string $url = self::URL,
        string $name = 'Alice',
        string $handle = 'alice@mastodon.example',
        ?string $avatar = 'https://cdn.example/avatar.jpg',
        ?\DateTimeImmutable $postedAt = null,
        string $text = 'Quoted text.',
        array $media = [],
        ?QuotedPostReference $nested = null,
    ): QuotedPostContent {
        return new QuotedPostContent($url, $name, $handle, $avatar, $postedAt, $text, $media, $nested);
    }
}
