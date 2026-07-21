<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\IpValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpValidatorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function blockedIps(): iterable
    {
        yield 'loopback v4' => ['127.0.0.1'];
        yield 'rfc1918 10/8' => ['10.1.2.3'];
        yield 'rfc1918 172.16/12' => ['172.16.0.1'];
        yield 'rfc1918 192.168/16' => ['192.168.1.1'];
        yield 'link-local' => ['169.254.169.254'];
        yield 'cgnat 100.64/10' => ['100.64.0.1'];
        yield 'this-net 0/8' => ['0.0.0.0'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'reserved 240/4' => ['255.255.255.255'];
        yield 'v6 loopback' => ['::1'];
        yield 'v6 unspecified' => ['::'];
        yield 'v6 ula' => ['fd12:3456::1'];
        yield 'v6 link-local' => ['fe80::1'];
        yield 'v6 multicast' => ['ff02::1'];
        yield 'v4-mapped private' => ['::ffff:192.168.1.1'];
        yield 'v4-mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'v4-compatible loopback' => ['::7f00:1'];
        yield 'v4-compatible loopback dotted' => ['::127.0.0.1'];
        yield 'nat64 private' => ['64:ff9b::a00:1'];
        yield 'nat64 loopback' => ['64:ff9b::7f00:1'];
        yield 'nat64 local-use' => ['64:ff9b:1::7f00:1'];
        yield '6to4 loopback' => ['2002:7f00:1::'];
        yield 'v6 site-local' => ['fec0::1'];
        yield 'garbage' => ['not-an-ip'];
    }

    #[DataProvider('blockedIps')]
    public function testBlocked(string $ip): void
    {
        self::assertFalse((new IpValidator())->isPublic($ip));
    }

    /** @return iterable<string, array{string}> */
    public static function publicIps(): iterable
    {
        yield 'public v4' => ['93.184.216.34'];
        yield 'public dns' => ['8.8.8.8'];
        yield 'public v6' => ['2606:4700:4700::1111'];
        yield 'v4-mapped public' => ['::ffff:8.8.8.8'];
    }

    #[DataProvider('publicIps')]
    public function testPublic(string $ip): void
    {
        self::assertTrue((new IpValidator())->isPublic($ip));
    }
}
