<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky\Support;

use App\Service\Bluesky\Support\AtPostUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AtPostUriTest extends TestCase
{
    private const string POST = 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mxhdhodv222n';

    /** @return iterable<string, array{string, bool}> */
    public static function uris(): iterable
    {
        yield 'a post' => [self::POST, true];
        yield 'a repost record' => ['at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.repost/3mxhdhodv222n', false];
        yield 'a post with a trailing path' => [self::POST . '/extra', false];
        yield 'a post with a trailing newline' => [self::POST . "\n", false];
        yield 'text before the scheme' => ['x' . self::POST, false];
        yield 'the web URL of a post' => ['https://bsky.app/profile/bsky.app/post/3mxhdhodv222n', false];
        yield 'a Mastodon guid' => ['https://mastodon.social/@Mastodon/115', false];
    }

    #[DataProvider('uris')]
    public function testMatchesAPostsAtUriOnly(string $uri, bool $matches): void
    {
        self::assertSame($matches, AtPostUri::matches($uri));
    }

    public function testThePostsWebUrlNamesItsDidAndRecordKey(): void
    {
        self::assertSame(
            'https://bsky.app/profile/did:plc:z72i7hdynmk6r22z27h6tvur/post/3mxhdhodv222n',
            AtPostUri::webUrl(self::POST),
        );
    }

    public function testAnythingElseHasNoWebUrl(): void
    {
        self::assertNull(AtPostUri::webUrl('at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.graph.starterpack/3lgml'));
    }

    #[DataProvider('authorities')]
    public function testTheAuthorityIsWhoseRepositoryHoldsThePost(string $uri, ?string $authority): void
    {
        self::assertSame($authority, AtPostUri::authorityOf($uri));
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function authorities(): iterable
    {
        yield 'a did' => [self::POST, 'did:plc:z72i7hdynmk6r22z27h6tvur'];
        yield 'a handle' => ['at://ann.test/app.bsky.feed.post/3k', 'ann.test'];
        yield 'a web url' => ['https://bsky.app/profile/ann.test/post/3k', null];
        yield 'another record kind' => ['at://ann.test/app.bsky.feed.repost/3k', null];
    }

    #[DataProvider('webUrls')]
    public function testAPostsWebUrlNamesItsPostByTheAuthorityItShows(string $url, ?string $uri): void
    {
        self::assertSame($uri, AtPostUri::ofWebUrl($url));
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function webUrls(): iterable
    {
        yield 'by handle' => ['https://bsky.app/profile/ann.test/post/3k', 'at://ann.test/app.bsky.feed.post/3k'];
        yield 'by did' => [
            'https://bsky.app/profile/did:plc:z72i7hdynmk6r22z27h6tvur/post/3mxhdhodv222n',
            self::POST,
        ];
        yield 'with a trailing slash and query' => [
            'https://www.bsky.app/profile/ann.test/post/3k/?ref=share',
            'at://ann.test/app.bsky.feed.post/3k',
        ];
        yield 'a post\'s likes page' => ['https://bsky.app/profile/ann.test/post/3k/liked-by', null];
        yield 'a profile' => ['https://bsky.app/profile/ann.test', null];
        yield 'another host' => ['https://bsky.example/profile/ann.test/post/3k', null];
        yield 'an at uri' => [self::POST, null];
    }

    public function testAPostCanBeNamedByAnotherAuthority(): void
    {
        self::assertSame(
            'at://did:plc:ann/app.bsky.feed.post/3k',
            AtPostUri::withAuthority('at://ann.test/app.bsky.feed.post/3k', 'did:plc:ann'),
        );
        self::assertSame('https://ann.test/3k', AtPostUri::withAuthority('https://ann.test/3k', 'did:plc:ann'));
    }
}
