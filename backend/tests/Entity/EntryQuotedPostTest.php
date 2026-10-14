<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryMedium;
use App\Entity\EntryQuotedPost;
use App\Entity\QuotedPostContent;
use App\Entity\QuotedPostReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryQuotedPostTest extends TestCase
{
    public function testAnEmptyEmbeddableHoldsNoQuote(): void
    {
        self::assertNull((new EntryQuotedPost())->read());
    }

    public function testAStoredQuoteReadsBackWhole(): void
    {
        $quote = new QuotedPostContent(
            'https://bsky.app/profile/did:plc:q/post/3k',
            'Quoted Person',
            'quoted.bsky.social',
            'https://cdn.bsky.app/img/avatar/plain/did:plc:q/a',
            new \DateTimeImmutable('2026-10-13 21:07:00'),
            "First line\nsecond line",
            [new EntryMedium('https://video.bsky.app/p.m3u8', 'video', 1080, 1920, 'https://video.bsky.app/t.jpg')],
            new QuotedPostReference('https://bsky.app/profile/did:plc:n/post/9z', 'nested.bsky.social'),
        );
        $stored = new EntryQuotedPost();

        $stored->store($quote);

        self::assertEquals($quote, $stored->read());
    }

    public function testANestedQuoteWithoutAHandleKeepsItsUrl(): void
    {
        $stored = new EntryQuotedPost();
        $stored->store(new QuotedPostContent(
            'https://mastodon.example/@a/1',
            'A',
            'a@mastodon.example',
            null,
            null,
            '',
            [],
            new QuotedPostReference('https://other.example/@b/2', null),
        ));

        self::assertEquals(new QuotedPostReference('https://other.example/@b/2', null), $stored->read()?->nestedQuote);
    }

    /** @return iterable<string, array{string}> */
    public static function requiredFields(): iterable
    {
        yield 'url' => ['url'];
        yield 'author name' => ['authorName'];
        yield 'author handle' => ['authorHandle'];
    }

    #[DataProvider('requiredFields')]
    public function testAStoredQuoteMissingARequiredFieldReadsAsNone(string $missing): void
    {
        $stored = new EntryQuotedPost();
        $stored->store(new QuotedPostContent('https://a.example/1', 'A', 'a.example', null, null, '', [], null));
        (new \ReflectionProperty($stored, $missing))->setValue($stored, null);

        self::assertNull($stored->read());
    }

    public function testClearingForgetsTheQuote(): void
    {
        $stored = new EntryQuotedPost();
        $stored->store(new QuotedPostContent('https://a.example/1', 'A', 'a.example', null, null, 'text', [], null));

        $stored->clear();

        self::assertNull($stored->read());
    }
}
