<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Enum\ProxyType;
use App\Service\Fetch\EgressOptions;
use App\Service\Fetch\GuardedUrl;
use App\Service\Fetch\ProxyConfig;
use PHPUnit\Framework\TestCase;

final class EgressOptionsTest extends TestCase
{
    public function testProxiedYieldsOnlyTheProxyOption(): void
    {
        $proxy = new ProxyConfig(ProxyType::Socks5, 'p', 1080, null, null);

        self::assertSame(['proxy' => 'socks5h://p:1080'], EgressOptions::proxied($proxy));
    }

    public function testPinnedYieldsTheResolvePinAndTheCrossFamilyKeyButNoProxy(): void
    {
        $guarded = new GuardedUrl('example.com', ['93.184.216.34']);

        $options = EgressOptions::pinned($guarded, 0);

        self::assertSame(['example.com' => '93.184.216.34'], $options['resolve']);
        self::assertArrayNotHasKey('proxy', $options);
    }

    public function testPinnedOnASecondAttemptAddsTheFreshConnectionExtra(): void
    {
        $guarded = new GuardedUrl('dual.example.com', ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']);

        $options = EgressOptions::pinned($guarded, 1);

        self::assertSame(['dual.example.com' => '93.184.216.34'], $options['resolve']);
        self::assertArrayNotHasKey('proxy', $options);
        self::assertTrue($options['extra']['curl'][\CURLOPT_FRESH_CONNECT] ?? false);
    }
}
