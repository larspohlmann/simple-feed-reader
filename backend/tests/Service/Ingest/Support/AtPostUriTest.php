<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\Support;

use App\Service\Ingest\Support\AtPostUri;
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
}
