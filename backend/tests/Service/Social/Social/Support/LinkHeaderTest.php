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
        yield 'next on another host' => ['<https://evil.example/a>; rel="next"', 'https://evil.example/a'];
        yield 'prev only' => ['<https://mastodon.example/a?since_id=9>; rel="prev"', null];
        yield 'no header' => [null, null];
        yield 'not a link header' => ['nonsense', null];
    }

    #[DataProvider('headers')]
    public function testTheNextPageIsReadFromTheHeader(?string $header, ?string $next): void
    {
        self::assertSame($next, LinkHeader::next($header));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function pages(): iterable
    {
        yield 'same origin' => [self::ORIGIN, 'https://mastodon.example/a', true];
        yield 'another host' => [self::ORIGIN, 'https://evil.example/a', false];
        yield 'another port' => [self::ORIGIN, 'https://mastodon.example:8443/a', false];
        yield 'over http' => [self::ORIGIN, 'http://mastodon.example/a', false];
        yield 'default port on the instance' => ['https://mastodon.example:443', 'https://mastodon.example/a', true];
        yield 'default port on the page' => [self::ORIGIN, 'https://mastodon.example:443/a', true];
        yield 'http default port' => ['http://mastodon.example', 'http://mastodon.example:80/a', true];
        yield 'the https port over http' => ['http://mastodon.example', 'http://mastodon.example:443/a', false];
        yield 'case of scheme and host' => [self::ORIGIN, 'HTTPS://Mastodon.Example/a', true];
        yield 'no url' => [self::ORIGIN, 'nonsense', false];
    }

    #[DataProvider('pages')]
    public function testOnlyAPageOnTheInstancesOwnOriginIsOn(string $instance, string $page, bool $isOn): void
    {
        self::assertSame($isOn, LinkHeader::isOn($instance, $page));
    }
}
