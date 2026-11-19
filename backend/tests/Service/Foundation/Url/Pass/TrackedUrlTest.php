<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Url\Pass;

use App\Service\Foundation\Url\Pass\TrackedUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrackedUrlTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function strippedTiers(): iterable
    {
        yield 'a utm prefix' => ['https://example.com/a?utm_source=x', 'https://example.com/a', false];
        yield 'documented click ids' => ['https://example.com/a?fbclid=1&gclid=2', 'https://example.com/a', false];
        yield 'a documented fragment key' => ['https://example.com/a#xtor=RSS-3', 'https://example.com/a', false];
        yield 'an undocumented key everywhere' => ['https://example.com/a?sara_ref=x', 'https://example.com/a', true];
        yield 'a host-scoped share key' => [
            'https://www.youtube.com/watch?v=x&si=y',
            'https://www.youtube.com/watch?v=x',
            true,
        ];
        yield 'an undocumented host-scoped key' => ['https://x.com/a/status/1?s=20', 'https://x.com/a/status/1', true];
        yield 'a value-scoped feed marker' => ['https://example.com/a?cmp=rss', 'https://example.com/a', true];
        yield 'a documented key beside a feed marker' => [
            'https://example.com/a?utm_source=x&cmp=feed',
            'https://example.com/a',
            true,
        ];
        yield 'a feed marker key with another value' => [
            'https://example.com/a?ref=home&utm_source=x',
            'https://example.com/a?ref=home',
            false,
        ];
        yield 'an exact piano key is documented' => [
            'https://www.bbc.com/a?at_campaign=rss',
            'https://www.bbc.com/a',
            false,
        ];
        yield 'nothing stripped' => ['https://example.com/a?id=1', 'https://example.com/a?id=1', false];
        yield 'no question mark or fragment' => ['https://example.com/a', 'https://example.com/a', false];
        yield 'no host' => ['/a?sara_ref=x', '/a?sara_ref=x', false];
    }

    #[DataProvider('strippedTiers')]
    public function testOneReadGivesTheCleanedUrlAndWhetherAnUndocumentedKeyWent(
        string $url,
        string $cleaned,
        bool $undocumented,
    ): void {
        $read = TrackedUrl::fromUrl($url);

        self::assertSame($cleaned, $read->cleaned());
        self::assertSame($undocumented, $read->strippedAnUndocumentedKey());
    }

    public function testTheKeptQueryOfAHostLeavesItsShareKeysOut(): void
    {
        self::assertSame('v=x&t=90', TrackedUrl::inQuery('v=x&si=y&t=90&utm_source=z', 'WWW.YouTube.com')->keptQuery());
    }
}
