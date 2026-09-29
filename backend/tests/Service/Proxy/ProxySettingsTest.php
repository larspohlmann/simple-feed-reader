<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Http\Admin\ProxySettingsJson;
use App\Service\Proxy\ProxySettings;
use App\Service\Proxy\StoredProxySettings\StoredProxySettingsInterface;
use App\Tests\Support\ProxyPasswordCiphers;
use App\Tests\Support\SettingsRequests;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProxySettingsTest extends TestCase
{
    private ?ProxyServerSettings $stored = null;

    /**
     * @return array{
     *     enabled: bool, directFallback: bool, type: string, host: string, port: int,
     *     username: string|null, remoteDns: bool, hasPassword: bool,
     * }
     */
    private function viewOf(ProxySettings $settings): array
    {
        return ProxySettingsJson::from($settings->current());
    }

    public function testWithNoRowTheViewDescribesAnUnconfiguredProxy(): void
    {
        self::assertSame([
            'enabled' => false,
            'directFallback' => true,
            'type' => 'SOCKS5',
            'host' => '',
            'port' => 1080,
            'username' => null,
            'remoteDns' => false,
            'hasPassword' => false,
        ], $this->viewOf($this->settings()));
    }

    public function testUpdateThenViewHidesTheSecretButFlagsThatOneIsStored(): void
    {
        $settings = $this->settings();

        $settings->update(SettingsRequests::proxy(
            enabled: true,
            directFallback: true,
            type: 'SOCKS5',
            host: 'proxy.example',
            port: 1080,
            username: 'user',
            password: 'sw0rdfish',
        )->toUpdate());

        $view = $this->viewOf($settings);
        self::assertTrue($view['enabled']);
        self::assertTrue($view['directFallback']);
        self::assertSame('SOCKS5', $view['type']);
        self::assertSame('proxy.example', $view['host']);
        self::assertSame(1080, $view['port']);
        self::assertSame('user', $view['username']);
        self::assertTrue($view['hasPassword']);
        self::assertArrayNotHasKey('passwordHint', $view);
        self::assertArrayNotHasKey('password', $view);
    }

    public function testThePasswordIsStoredSealed(): void
    {
        $settings = $this->settings();

        $settings->update(SettingsRequests::proxy(host: 'proxy.example', password: 'sw0rdfish')->toUpdate());

        $stored = $this->stored;
        self::assertNotNull($stored);
        self::assertNotSame('sw0rdfish', $stored->getSealedPassword()->ciphertext);
        self::assertSame('sw0rdfish', ProxyPasswordCiphers::withTestSecret()->open($stored->getSealedPassword()));
    }

    public function testANullPasswordKeepsTheStoredSecretWhileTheConnectionChanges(): void
    {
        $settings = $this->settings();
        $settings->update(
            SettingsRequests::proxy(enabled: true, host: 'a', port: 1, password: 'sw0rdfish')->toUpdate(),
        );

        $settings->update(
            SettingsRequests::proxy(directFallback: false, type: 'HTTP', host: 'b', port: 2)->toUpdate(),
        );

        $stored = $this->stored;
        self::assertNotNull($stored);
        self::assertSame('sw0rdfish', ProxyPasswordCiphers::withTestSecret()->open($stored->getSealedPassword()));
        $view = $this->viewOf($settings);
        self::assertFalse($view['enabled']);
        self::assertFalse($view['directFallback']);
        self::assertSame('HTTP', $view['type']);
        self::assertSame('b', $view['host']);
        self::assertSame(2, $view['port']);
    }

    public function testRemovePasswordClearsTheStoredSecretAndStillAppliesTheConnection(): void
    {
        $settings = $this->settings();
        $settings->update(
            SettingsRequests::proxy(host: 'proxy.example', username: 'user', password: 'sw0rdfish')->toUpdate(),
        );
        self::assertTrue($this->viewOf($settings)['hasPassword']);

        $settings->update(
            SettingsRequests::proxy(host: 'other.example', username: 'user', removePassword: true)->toUpdate(),
        );

        $view = $this->viewOf($settings);
        self::assertFalse($view['hasPassword']);
        self::assertSame('other.example', $view['host']);
    }

    public function testRemoteDnsIsStoredAndShown(): void
    {
        $settings = $this->settings();

        $settings->update(SettingsRequests::proxy(host: 'proxy.example', remoteDns: true)->toUpdate());

        self::assertTrue($this->viewOf($settings)['remoteDns']);
    }

    public function testUpdateFlushesTheEntityManager(): void
    {
        $repository = $this->createStub(StoredProxySettingsInterface::class);
        $repository->method('findSingleton')->willReturn(new ProxyServerSettings());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        (new ProxySettings($repository, $em, ProxyPasswordCiphers::withTestSecret()))
            ->update(SettingsRequests::proxy(host: 'proxy.example', password: 'pw123456')->toUpdate());
    }

    private function settings(): ProxySettings
    {
        $repository = $this->createStub(StoredProxySettingsInterface::class);
        $repository->method('findSingleton')->willReturnCallback(fn (): ?ProxyServerSettings => $this->stored);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof ProxyServerSettings) {
                $this->stored = $entity;
            }
        });

        return new ProxySettings($repository, $em, ProxyPasswordCiphers::withTestSecret());
    }
}
