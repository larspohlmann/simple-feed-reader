<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Service\Discovery\SubstackProfileFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubstackProfileFeedTest extends TestCase
{
    #[DataProvider('profileUrls')]
    public function testRewritesAProfileUrlToItsPublicationFeed(string $enteredUrl, string $expected): void
    {
        self::assertSame($expected, (new SubstackProfileFeed())->feedUrl($enteredUrl));
    }

    /** @return iterable<string, array{string, string}> */
    public static function profileUrls(): iterable
    {
        yield 'the share URL with its tracking query' => [
            'https://substack.com/@rushkoff?r=260csv&utm_medium=ios&utm_source=stories',
            'https://rushkoff.substack.com/feed',
        ];
        yield 'a bare profile URL' => [
            'https://substack.com/@rushkoff',
            'https://rushkoff.substack.com/feed',
        ];
        yield 'a trailing slash' => [
            'https://substack.com/@rushkoff/',
            'https://rushkoff.substack.com/feed',
        ];
        yield 'the www host' => [
            'https://www.substack.com/@rushkoff',
            'https://rushkoff.substack.com/feed',
        ];
        yield 'a mixed-case host and handle canonicalised to lowercase' => [
            'https://Substack.com/@Rushkoff',
            'https://rushkoff.substack.com/feed',
        ];
        yield 'a handle with a hyphen and underscore' => [
            'https://substack.com/@the_daily-widget',
            'https://the_daily-widget.substack.com/feed',
        ];
    }

    #[DataProvider('nonProfileUrls')]
    public function testLeavesEverythingElseAlone(string $enteredUrl): void
    {
        self::assertNull((new SubstackProfileFeed())->feedUrl($enteredUrl));
    }

    /** @return iterable<string, array{string}> */
    public static function nonProfileUrls(): iterable
    {
        yield 'a publication URL already resolves via probing' => ['https://rushkoff.substack.com'];
        yield 'a publication feed URL' => ['https://rushkoff.substack.com/feed'];
        yield 'a Substack post URL' => ['https://substack.com/home/post/p-123'];
        yield 'a deeper profile path such as a note' => ['https://substack.com/@rushkoff/note/c-1'];
        yield 'an @handle that is not at the path root' => ['https://substack.com/section/@rushkoff'];
        yield 'a profile with no handle' => ['https://substack.com/@'];
        yield 'a look-alike host' => ['https://substack.com.evil.example/@rushkoff'];
        yield 'a non-Substack host' => ['https://example.com/@rushkoff'];
        yield 'a substack subdomain profile path is not the share form' => [
            'https://rushkoff.substack.com/@rushkoff',
        ];
    }
}
