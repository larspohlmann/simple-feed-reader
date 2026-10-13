<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\BlueskyRecordUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyRecordUriTest extends TestCase
{
    #[DataProvider('records')]
    public function testListsAndFeedGeneratorsAreToldApart(string $uri, bool $isList, bool $isFeedGenerator): void
    {
        self::assertSame($isList, BlueskyRecordUri::isList($uri));
        self::assertSame($isFeedGenerator, BlueskyRecordUri::isFeedGenerator($uri));
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function records(): iterable
    {
        yield 'a list' => ['at://did:plc:bob/app.bsky.graph.list/3l1', true, false];
        yield 'a feed generator' => ['at://did:web:example.com/app.bsky.feed.generator/news', false, true];
        yield 'a list collection with its dots replaced' => ['at://did:plc:bob/appxbsky.graph.list/3l1', false, false];
        yield 'a generator collection with its dots replaced' => [
            'at://did:plc:bob/app.bsky.feedxgenerator/news',
            false,
            false,
        ];
        yield 'a post' => ['at://did:plc:bob/app.bsky.feed.post/3l1', false, false];
        yield 'a handle for the repository' => ['at://bob.bsky.social/app.bsky.graph.list/3l1', false, false];
        yield 'a DID method with a digit' => ['at://did:pl4:bob/app.bsky.graph.list/3l1', false, false];
        yield 'a DID ending in a colon' => ['at://did:plc:bob:/app.bsky.graph.list/3l1', false, false];
        yield 'a record key with a slash' => ['at://did:plc:bob/app.bsky.graph.list/3l1/x', false, false];
        yield 'the longest record key' => ['at://did:plc:bob/app.bsky.graph.list/' . str_repeat('k', 512), true, false];
        yield 'a record key over 512' => ['at://did:plc:bob/app.bsky.graph.list/' . str_repeat('k', 513), false, false];
        yield 'a trailing newline' => ["at://did:plc:bob/app.bsky.graph.list/3l1\n", false, false];
        yield 'empty' => ['', false, false];
    }

    #[DataProvider('webUrls')]
    public function testTheWebPageOfARecord(string $uri, ?string $webUrl): void
    {
        self::assertSame($webUrl, BlueskyRecordUri::webUrl($uri));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function webUrls(): iterable
    {
        yield 'a list' => [
            'at://did:plc:bob/app.bsky.graph.list/3l1',
            'https://bsky.app/profile/did:plc:bob/lists/3l1',
        ];
        yield 'a feed generator' => [
            'at://did:plc:bob/app.bsky.feed.generator/news',
            'https://bsky.app/profile/did:plc:bob/feed/news',
        ];
        yield 'no record' => ['at://did:plc:bob/app.bsky.feed.post/3l1', null];
        yield 'a like' => ['at://did:plc:bob/app.bsky.feed.like/3l1', null];
    }

    #[DataProvider('likes')]
    public function testOnlyALikeRecordTheColumnCanHoldIsALike(?string $uri, ?string $like): void
    {
        self::assertSame($like, BlueskyRecordUri::likeOrNull($uri));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function likes(): iterable
    {
        $like = 'at://did:plc:alice/app.bsky.feed.like/3lk2';
        yield 'a like' => [$like, $like];
        yield 'did:web with a port' => [
            'at://did:web:localhost%3A3000/app.bsky.feed.like/3lk2',
            'at://did:web:localhost%3A3000/app.bsky.feed.like/3lk2',
        ];
        yield 'exactly 512 characters' => [self::likeOfLength(512), self::likeOfLength(512)];
        yield 'over 512 characters' => [self::likeOfLength(513), null];
        yield 'a post' => ['at://did:plc:alice/app.bsky.feed.post/3lk2', null];
        yield 'a list' => ['at://did:plc:alice/app.bsky.graph.list/3lk2', null];
        yield 'an empty record key' => ['at://did:plc:alice/app.bsky.feed.like/', null];
        yield 'nothing' => [null, null];
    }

    #[DataProvider('recordKeys')]
    public function testTheRecordKeyIsTheLastSegmentOfAKnownRecord(string $uri, ?string $recordKey): void
    {
        self::assertSame($recordKey, BlueskyRecordUri::recordKeyOf($uri));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function recordKeys(): iterable
    {
        yield 'a like' => ['at://did:plc:alice/app.bsky.feed.like/3lk2', '3lk2'];
        yield 'a list' => ['at://did:plc:alice/app.bsky.graph.list/3l1', '3l1'];
        yield 'a post' => ['at://did:plc:alice/app.bsky.feed.post/3lk2', null];
        yield 'no uri' => ['3lk2', null];
    }

    private static function likeOfLength(int $length): string
    {
        $prefix = 'at://did:plc:alice/app.bsky.feed.like/';

        return $prefix . str_repeat('a', $length - \strlen($prefix));
    }
}
