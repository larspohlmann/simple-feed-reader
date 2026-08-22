<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Enum\ProxyType;
use App\Service\Fetch\ProxyConfig;
use App\Service\Fetch\ProxyEgressResolver;
use App\Service\Proxy\ProxySettings;
use PHPUnit\Framework\TestCase;

final class ProxyEgressResolverTest extends TestCase
{
    public function testResolvesTheEgressProxyWhenEnabled(): void
    {
        $config = new ProxyConfig(ProxyType::Socks5, 'p', 1080, null, null);
        $settings = $this->createStub(ProxySettings::class);
        $settings->method('egressProxy')->willReturn($config);

        self::assertSame($config, (new ProxyEgressResolver($settings))->resolve());
    }

    public function testResolvesNullWhenDisabledOrUnconfigured(): void
    {
        $settings = $this->createStub(ProxySettings::class);
        $settings->method('egressProxy')->willReturn(null);

        self::assertNull((new ProxyEgressResolver($settings))->resolve());
    }
}
