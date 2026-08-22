<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Enum\ProxyType;
use App\Service\Fetch\ProxyConfig;
use PHPUnit\Framework\TestCase;

final class ProxyConfigTest extends TestCase
{
    public function testSocks5DsnUsesRemoteDnsScheme(): void
    {
        $config = new ProxyConfig(ProxyType::Socks5, 'proxy.example', 1080, null, null);

        self::assertSame('socks5h://proxy.example:1080', $config->dsn());
    }

    public function testHttpDsnUsesHttpScheme(): void
    {
        $config = new ProxyConfig(ProxyType::Http, 'proxy.example', 8080, null, null);

        self::assertSame('http://proxy.example:8080', $config->dsn());
    }

    public function testCredentialsAreEmbeddedAndUrlEncoded(): void
    {
        $config = new ProxyConfig(ProxyType::Socks5, 'proxy.example', 1080, 'user@pia', 'p@ss:word');

        self::assertSame('socks5h://user%40pia:p%40ss%3Aword@proxy.example:1080', $config->dsn());
    }

    public function testUsernameWithoutPasswordStillAuthenticates(): void
    {
        $config = new ProxyConfig(ProxyType::Http, 'proxy.example', 8080, 'user', null);

        self::assertSame('http://user:@proxy.example:8080', $config->dsn());
    }
}
