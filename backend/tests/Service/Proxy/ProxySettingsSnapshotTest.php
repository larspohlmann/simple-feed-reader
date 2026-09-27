<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use App\Service\Crypto\SealedSecret;
use App\Service\Proxy\ProxyConnection;
use App\Service\Proxy\ProxySettingsSnapshot;
use PHPUnit\Framework\TestCase;

final class ProxySettingsSnapshotTest extends TestCase
{
    public function testItCarriesTheRowsConnectionAndWhetherAPasswordIsStored(): void
    {
        $connection = new ProxyConnection(true, false, ProxyType::Http, 'proxy.example', 3128, 'user', true);
        $row = new ProxyServerSettings();
        $row->apply($connection, new SealedSecret('cipher', 'nonce', 'salt', 1));

        $snapshot = ProxySettingsSnapshot::fromEntity($row);

        self::assertEquals($connection, $snapshot->connection);
        self::assertTrue($snapshot->hasPassword);
    }

    public function testAFreshRowIsNotConfiguredAndHoldsNoPassword(): void
    {
        $snapshot = ProxySettingsSnapshot::fromEntity(new ProxyServerSettings());

        self::assertFalse($snapshot->isConfigured());
        self::assertFalse($snapshot->hasPassword);
    }

    public function testARowWithAHostIsConfiguredWhetherOrNotItIsEnabled(): void
    {
        $snapshot = new ProxySettingsSnapshot(
            new ProxyConnection(false, true, ProxyType::Socks5, 'proxy.example', 1080, null),
            false,
        );

        self::assertTrue($snapshot->isConfigured());
    }
}
