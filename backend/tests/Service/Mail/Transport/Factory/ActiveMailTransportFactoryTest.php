<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport\Factory;

use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\Model\ResolvedMailTransportModel;
use App\Service\Mail\Transport\Factory\ActiveMailTransportFactory;
use App\Service\Mail\Transport\Factory\EsmtpTransportFactory;
use App\Service\Mail\Transport\Pass\CurlSmtpTransport;
use App\Service\Proxy\ConfiguredProxySource\ConfiguredProxySourceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ActiveMailTransportFactoryTest extends TestCase
{
    public function testDirectResolvedGivesAnEsmtpTransport(): void
    {
        $factory = $this->factory(null);
        $resolved = new ResolvedMailTransportModel('h', 587, 'u', 'p', MailEncryption::Starttls, false);

        self::assertInstanceOf(EsmtpTransport::class, $factory->forResolved($resolved, null, new NullLogger()));
    }

    public function testProxiedResolvedGivesACurlTransport(): void
    {
        $factory = $this->factory(
            new ProxyConfigModel(ProxyType::Socks5, 'proxy.example', 1080, null, null, true, true),
        );
        $resolved = new ResolvedMailTransportModel('smtp.gmail.com', 587, 'u', 'p', MailEncryption::Starttls, true);

        self::assertInstanceOf(CurlSmtpTransport::class, $factory->forResolved($resolved, null, new NullLogger()));
    }

    public function testProxiedResolvedWithNoProxyThrows(): void
    {
        $factory = $this->factory(null);
        $resolved = new ResolvedMailTransportModel('smtp.gmail.com', 587, 'u', 'p', MailEncryption::Starttls, true);

        $this->expectException(IncompleteMailConfigurationException::class);
        $factory->forResolved($resolved, null, new NullLogger());
    }

    public function testFallbackDsnBuildsTheTransportForThatDsn(): void
    {
        self::assertInstanceOf(
            NullTransport::class,
            $this->factory(null)->forFallbackDsn('null://null', null, new NullLogger()),
        );
    }

    public function testADirectRowIsSignedByItsOwnSettingsAlone(): void
    {
        $resolved = new ResolvedMailTransportModel('h', 587, 'u', 'p', MailEncryption::Starttls, false);

        self::assertSame($resolved->signature(), $this->factory(self::proxy())->signatureOf($resolved));
    }

    public function testAProxiedRowIsSignedByItsSettingsAndItsProxy(): void
    {
        $resolved = new ResolvedMailTransportModel('h', 587, 'u', 'p', MailEncryption::Starttls, true);

        self::assertSame(
            $resolved->signature() . '|' . self::proxy()->signature(),
            $this->factory(self::proxy())->signatureOf($resolved),
        );
    }

    public function testAProxiedRowWithoutAProxyIsSignedAsMissingIt(): void
    {
        $resolved = new ResolvedMailTransportModel('h', 587, 'u', 'p', MailEncryption::Starttls, true);

        self::assertSame($resolved->signature() . '|proxy-missing', $this->factory(null)->signatureOf($resolved));
    }

    private static function proxy(): ProxyConfigModel
    {
        return new ProxyConfigModel(ProxyType::Socks5, 'proxy.example', 1080, null, null, true, true);
    }

    private function factory(?ProxyConfigModel $configuredProxy): ActiveMailTransportFactory
    {
        $proxySource = $this->createStub(ConfiguredProxySourceInterface::class);
        $proxySource->method('configuredProxy')->willReturn($configuredProxy);

        return new ActiveMailTransportFactory(
            $proxySource,
            $this->createStub(HttpClientInterface::class),
            new EsmtpTransportFactory(),
        );
    }
}
