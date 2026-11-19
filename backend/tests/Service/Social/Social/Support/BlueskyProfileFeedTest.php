<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Support\BlueskyProfileFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyProfileFeedTest extends TestCase
{
    public function testABlueskyProfileFeedIsBuiltFromTheDid(): void
    {
        self::assertSame('https://bsky.app/profile/did:plc:bob/rss', BlueskyProfileFeed::buildUrl('did:plc:bob'));
    }

    #[DataProvider('profilePages')]
    public function testABlueskyProfilePageOrItsFeedNamesItsActor(string $url, ?string $actor): void
    {
        self::assertSame($actor, BlueskyProfileFeed::parseProfileActor($url));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function profilePages(): iterable
    {
        yield 'a page by handle' => ['https://bsky.app/profile/Rusty-17.bsky.social', 'rusty-17.bsky.social'];
        yield 'its feed' => ['https://bsky.app/profile/rusty-17.bsky.social/rss', 'rusty-17.bsky.social'];
        yield 'a trailing slash' => ['https://bsky.app/profile/rusty-17.bsky.social/', 'rusty-17.bsky.social'];
        yield 'a page by did' => [
            'http://bsky.app/profile/did:plc:hczfd45prvqtdrw77256syag',
            'did:plc:hczfd45prvqtdrw77256syag',
        ];
        yield 'a post' => ['https://bsky.app/profile/rusty-17.bsky.social/post/3abc', null];
        yield 'another host' => ['https://example.com/profile/rusty-17.bsky.social', null];
        yield 'no actor syntax' => ['https://bsky.app/profile/some%20name', null];
    }
}
