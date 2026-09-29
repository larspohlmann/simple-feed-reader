<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Enum\ProxyType;
use App\Service\Fetch\Model\ProxyConfigModel;
use PHPUnit\Framework\TestCase;

final class ProxyConfigModelTest extends TestCase
{
    public function testSocks5DsnResolvesLocallyByDefault(): void
    {
        $config = new ProxyConfigModel(ProxyType::Socks5, 'proxy.example', 1080, null, null);

        self::assertSame('socks5://proxy.example:1080', $config->dsn());
    }

    public function testSocks5DsnUsesTheRemoteDnsSchemeWhenAsked(): void
    {
        $config = new ProxyConfigModel(ProxyType::Socks5, 'proxy.example', 1080, null, null, remoteDns: true);

        self::assertSame('socks5h://proxy.example:1080', $config->dsn());
    }

    /** An HTTP proxy always resolves the name itself, so the switch cannot apply. */
    public function testHttpDsnIsUnaffectedByTheDnsSwitch(): void
    {
        $config = new ProxyConfigModel(ProxyType::Http, 'proxy.example', 8080, null, null, remoteDns: true);

        self::assertSame('http://proxy.example:8080', $config->dsn());
    }

    public function testHttpDsnUsesHttpScheme(): void
    {
        $config = new ProxyConfigModel(ProxyType::Http, 'proxy.example', 8080, null, null);

        self::assertSame('http://proxy.example:8080', $config->dsn());
    }

    public function testCredentialsAreEmbeddedAndUrlEncoded(): void
    {
        $config = new ProxyConfigModel(ProxyType::Socks5, 'proxy.example', 1080, 'user@pia', 'p@ss:word');

        self::assertSame('socks5://user%40pia:p%40ss%3Aword@proxy.example:1080', $config->dsn());
    }

    public function testUsernameWithoutPasswordStillAuthenticates(): void
    {
        $config = new ProxyConfigModel(ProxyType::Http, 'proxy.example', 8080, 'user', null);

        self::assertSame('http://user:@proxy.example:8080', $config->dsn());
    }

    public function testPlainSocks5ResolvesTheNameLocally(): void
    {
        $config = new ProxyConfigModel(ProxyType::Socks5, 'proxy.example', 1080, null, null);

        self::assertTrue($config->resolvesLocally());
    }

    public function testRemoteDnsSocks5LeavesResolutionToTheProxy(): void
    {
        $config = new ProxyConfigModel(ProxyType::Socks5, 'proxy.example', 1080, null, null, remoteDns: true);

        self::assertFalse($config->resolvesLocally());
    }

    public function testHttpProxyLeavesResolutionToTheProxy(): void
    {
        $config = new ProxyConfigModel(ProxyType::Http, 'proxy.example', 8080, null, null);

        self::assertFalse($config->resolvesLocally());
    }

    public function testTheSignatureCarriesThePasswordOnlyAsADigest(): void
    {
        $config = new ProxyConfigModel(ProxyType::Socks5, 'proxy.example', 1080, 'user', 'secret', remoteDns: true);

        self::assertSame(
            'SOCKS5|proxy.example|1080|user|' . hash('sha256', 'secret') . '|remote-dns',
            $config->signature(),
        );
    }

    public function testTheSignatureOfAProxyWithoutCredentials(): void
    {
        $config = new ProxyConfigModel(ProxyType::Http, 'proxy.example', 8080, null, null);

        self::assertSame('HTTP|proxy.example|8080||no-pass|local-dns', $config->signature());
    }
}
