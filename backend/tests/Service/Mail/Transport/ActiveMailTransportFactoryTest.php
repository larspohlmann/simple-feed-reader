<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\ResolvedMailTransport;
use App\Service\Mail\Transport\ActiveMailTransportFactory;
use App\Service\Mail\Transport\CurlSmtpTransport;
use App\Service\Proxy\ProxySettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class ActiveMailTransportFactoryTest extends TestCase
{
    public function testDirectResolvedGivesAnEsmtpTransport(): void
    {
        $proxySettings = $this->createMock(ProxySettings::class);
        $factory = new ActiveMailTransportFactory($proxySettings);
        $resolved = new ResolvedMailTransport('h', 587, 'u', 'p', MailEncryption::Starttls, false);

        self::assertInstanceOf(EsmtpTransport::class, $factory->forResolved($resolved, null, new NullLogger()));
    }

    public function testProxiedResolvedGivesACurlTransport(): void
    {
        $proxySettings = $this->createMock(ProxySettings::class);
        $proxySettings->method('configuredProxy')->willReturn(
            new ProxyConfig(ProxyType::Socks5, 'proxy.example', 1080, null, null, true, true),
        );
        $factory = new ActiveMailTransportFactory($proxySettings);
        $resolved = new ResolvedMailTransport('smtp.gmail.com', 587, 'u', 'p', MailEncryption::Starttls, true);

        self::assertInstanceOf(CurlSmtpTransport::class, $factory->forResolved($resolved, null, new NullLogger()));
    }

    public function testProxiedResolvedWithNoProxyThrows(): void
    {
        $proxySettings = $this->createMock(ProxySettings::class);
        $proxySettings->method('configuredProxy')->willReturn(null);
        $factory = new ActiveMailTransportFactory($proxySettings);
        $resolved = new ResolvedMailTransport('smtp.gmail.com', 587, 'u', 'p', MailEncryption::Starttls, true);

        $this->expectException(IncompleteMailConfigurationException::class);
        $factory->forResolved($resolved, null, new NullLogger());
    }
}
