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
use App\Service\Proxy\ConfiguredProxySource;
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
        $resolved = new ResolvedMailTransport('h', 587, 'u', 'p', MailEncryption::Starttls, false);

        self::assertInstanceOf(EsmtpTransport::class, $factory->forResolved($resolved, null, new NullLogger()));
    }

    public function testProxiedResolvedGivesACurlTransport(): void
    {
        $factory = $this->factory(new ProxyConfig(ProxyType::Socks5, 'proxy.example', 1080, null, null, true, true));
        $resolved = new ResolvedMailTransport('smtp.gmail.com', 587, 'u', 'p', MailEncryption::Starttls, true);

        self::assertInstanceOf(CurlSmtpTransport::class, $factory->forResolved($resolved, null, new NullLogger()));
    }

    public function testProxiedResolvedWithNoProxyThrows(): void
    {
        $factory = $this->factory(null);
        $resolved = new ResolvedMailTransport('smtp.gmail.com', 587, 'u', 'p', MailEncryption::Starttls, true);

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

    private function factory(?ProxyConfig $configuredProxy): ActiveMailTransportFactory
    {
        $proxySource = $this->createStub(ConfiguredProxySource::class);
        $proxySource->method('configuredProxy')->willReturn($configuredProxy);

        return new ActiveMailTransportFactory($proxySource, $this->createStub(HttpClientInterface::class));
    }
}
