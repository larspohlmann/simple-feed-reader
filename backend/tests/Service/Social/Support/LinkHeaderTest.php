<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\LinkHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinkHeaderTest extends TestCase
{
    private const string ORIGIN = 'https://mastodon.example';

    /** @return iterable<string, array{?string, ?string}> */
    public static function headers(): iterable
    {
        yield 'next among several' => [
            '<https://mastodon.example/a?max_id=5>; rel="next", <https://mastodon.example/a?since_id=9>; rel="prev"',
            'https://mastodon.example/a?max_id=5',
        ];
        yield 'prev only' => ['<https://mastodon.example/a?since_id=9>; rel="prev"', null];
        yield 'next on another host' => ['<https://evil.example/a>; rel="next"', null];
        yield 'next on another port' => ['<https://mastodon.example:8443/a>; rel="next"', null];
        yield 'next over http' => ['<http://mastodon.example/a>; rel="next"', null];
        yield 'no header' => [null, null];
        yield 'not a link header' => ['nonsense', null];
    }

    #[DataProvider('headers')]
    public function testOnlyANextPageOnTheInstancesOwnOriginIsFollowed(?string $header, ?string $next): void
    {
        self::assertSame($next, LinkHeader::nextOn(self::ORIGIN, $header));
    }
}
