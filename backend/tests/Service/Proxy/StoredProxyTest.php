<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Crypto\SealedSecret;
use App\Service\Proxy\ProxyConnection;
use App\Service\Proxy\StoredProxy;
use App\Tests\Support\ProxyPasswordCiphers;
use App\Tests\Support\StoredProxies;
use PHPUnit\Framework\TestCase;

final class StoredProxyTest extends TestCase
{
    use StoredProxies;

    public function testNothingIsConfiguredWithoutARow(): void
    {
        $storedProxy = $this->storedProxy(null);

        self::assertNull($storedProxy->configuredProxy());
        self::assertNull($storedProxy->egressProxy());
    }

    public function testARowWithoutAHostIsNotAProxy(): void
    {
        $row = new ProxyServerSettings();
        $row->applyWithoutPassword(new ProxyConnection(true, true, ProxyType::Socks5, '', 1080, null));

        self::assertNull($this->storedProxy($row)->egressProxy());
    }

    public function testTheConfiguredProxyCarriesEveryFieldAndTheOpenedPassword(): void
    {
        $row = $this->row(
            new ProxyConnection(true, false, ProxyType::Http, 'proxy.example', 3128, 'user', true),
            'sw0rdfish',
        );

        $proxy = $this->storedProxy($row)->configuredProxy();

        self::assertNotNull($proxy);
        self::assertSame(ProxyType::Http, $proxy->type);
        self::assertSame('proxy.example', $proxy->host);
        self::assertSame(3128, $proxy->port);
        self::assertSame('user', $proxy->username);
        self::assertSame('sw0rdfish', $proxy->password);
        self::assertFalse($proxy->directFallback);
        self::assertTrue($proxy->remoteDns);
    }

    public function testARowWithoutAPasswordGivesAProxyWithoutOne(): void
    {
        $row = new ProxyServerSettings();
        $row->applyWithoutPassword(new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, null));

        $proxy = $this->storedProxy($row)->configuredProxy();

        self::assertNotNull($proxy);
        self::assertNull($proxy->password);
    }

    public function testADisabledProxyIsConfiguredButNotUsedForEgress(): void
    {
        $row = $this->row(new ProxyConnection(false, true, ProxyType::Socks5, 'proxy.example', 1080, null), 'pw');
        $storedProxy = $this->storedProxy($row);

        self::assertNull($storedProxy->egressProxy());
        self::assertNotNull($storedProxy->configuredProxy());
    }

    public function testAnEnabledProxyIsUsedForEgressWithLocalDnsByDefault(): void
    {
        $row = $this->row(new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, null), 'pw');

        self::assertSame('socks5://proxy.example:1080', $this->storedProxy($row)->egressProxy()?->dsn());
    }

    public function testRemoteDnsReachesTheEgressScheme(): void
    {
        $row = $this->row(
            new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, null, true),
            'pw',
        );

        self::assertSame('socks5h://proxy.example:1080', $this->storedProxy($row)->egressProxy()?->dsn());
    }

    public function testAPasswordThatCannotBeOpenedIsReportedAsUnreadable(): void
    {
        $row = new ProxyServerSettings();
        $row->apply(
            new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, 'user'),
            new SealedSecret('not base64!', 'bm9uY2U=', 'c2FsdA==', 1),
        );

        $this->expectException(SecretUnreadableException::class);

        $this->storedProxy($row)->egressProxy();
    }

    private function row(ProxyConnection $connection, string $password): ProxyServerSettings
    {
        $row = new ProxyServerSettings();
        $row->apply($connection, ProxyPasswordCiphers::withTestSecret()->seal($password));

        return $row;
    }

    private function storedProxy(?ProxyServerSettings $row): StoredProxy
    {
        return $this->storedProxyOver($row, ProxyPasswordCiphers::withTestSecret());
    }
}
