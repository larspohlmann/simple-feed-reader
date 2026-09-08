<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\HostKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostKeyTest extends TestCase
{
    #[DataProvider('urls')]
    public function testFoldsUrlsToAComparableHostKey(string $url, string $expected): void
    {
        self::assertSame($expected, HostKey::forUrl($url));
    }

    /** @return iterable<string, array{string, string}> */
    public static function urls(): iterable
    {
        yield 'plain host' => ['https://example.com/feed', 'example.com'];
        yield 'uppercased host is lowercased' => ['https://Example.COM/feed', 'example.com'];
        yield 'leading www. is dropped' => ['https://www.example.com/feed', 'example.com'];
        yield 'only a leading www. is dropped' => ['https://wwwexample.com/feed', 'wwwexample.com'];
        yield 'an explicit port is ignored' => ['https://example.com:8443/feed', 'example.com'];
        yield 'www. and port fold together' => ['https://www.example.com:443/feed', 'example.com'];
        yield 'a subdomain other than www is kept' => ['https://feeds.example.com/rss', 'feeds.example.com'];
    }

    public function testFallsBackToTheRawInputWhenNoHostCanBeParsed(): void
    {
        self::assertSame('not-a-url', HostKey::forUrl('not-a-url'));
    }
}
