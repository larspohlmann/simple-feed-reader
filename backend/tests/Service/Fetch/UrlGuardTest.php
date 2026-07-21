<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlGuardTest extends TestCase
{
    /** @param array<string, list<string>> $dnsMap */
    private function guard(array $dnsMap = []): UrlGuard
    {
        $resolver = new class ($dnsMap) implements DnsResolverInterface {
            /** @param array<string, list<string>> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function resolve(string $hostname): array
            {
                return $this->map[$hostname] ?? [];
            }
        };

        return new UrlGuard($resolver, new IpValidator());
    }

    public function testAllowsPublicHostAndReturnsResolvedIp(): void
    {
        $guarded = $this->guard(['example.com' => ['93.184.216.34']])
            ->assertSafe('https://example.com/feed.xml');

        self::assertSame('example.com', $guarded->host);
        self::assertSame('93.184.216.34', $guarded->ip);
    }

    public function testAllowsPublicIpLiteralWithoutDns(): void
    {
        $guarded = $this->guard()->assertSafe('http://93.184.216.34/feed');

        self::assertSame('93.184.216.34', $guarded->host);
        self::assertSame('93.184.216.34', $guarded->ip);
    }

    public function testPinsTheFirstValidatedRecord(): void
    {
        $guarded = $this->guard(['multi.example.com' => ['93.184.216.34', '8.8.8.8']])
            ->assertSafe('https://multi.example.com/feed');

        self::assertSame('93.184.216.34', $guarded->ip);
    }

    /** @return iterable<string, array{string}> */
    public static function blockedUrls(): iterable
    {
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'ftp scheme' => ['ftp://example.com/feed'];
        yield 'loopback literal' => ['http://127.0.0.1/feed'];
        yield 'private literal' => ['http://10.0.0.5/feed'];
        yield 'v6 loopback literal' => ['http://[::1]/feed'];
        yield 'credentials in url' => ['https://user:pass@example.com/feed'];
        yield 'malformed' => ['http:///nohost'];
    }

    #[DataProvider('blockedUrls')]
    public function testBlockedWithoutDns(string $url): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->guard(['example.com' => ['93.184.216.34']])->assertSafe($url);
    }

    public function testBlocksHostResolvingToPrivateIp(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->guard(['evil.example.com' => ['169.254.169.254']])->assertSafe('https://evil.example.com/');
    }

    public function testBlocksWhenAnyRecordIsPrivate(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->guard(['dual.example.com' => ['93.184.216.34', '10.0.0.1']])->assertSafe('https://dual.example.com/');
    }

    public function testBlocksWhenDnsResolutionFails(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->guard()->assertSafe('https://unresolvable.example.com/');
    }
}
