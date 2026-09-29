<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Entity\MailConnection;
use App\Entity\MailServerSettings;
use App\Entity\SealedSecret;
use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\MailSettings;
use App\Service\Mail\Settings\Model\ResolvedMailTransportModel;
use App\Service\Mail\Transport\DynamicMailTransport;
use App\Service\Mail\Transport\Factory\ActiveMailTransportFactory;
use App\Service\Mail\Transport\Factory\EsmtpTransportFactory;
use App\Service\Mail\Transport\Pass\CurlSmtpTransport;
use App\Service\Proxy\ConfiguredProxySource\ConfiguredProxySourceInterface;
use App\Tests\Support\ConfiguresAProxy;
use App\Tests\Support\SettingsRequests;
use App\Tests\Support\UnreadableProxyPasswordRows;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DynamicMailTransportTest extends KernelTestCase
{
    use ConfiguresAProxy;

    private ?ProxyConfigModel $configuredProxy = null;

    /** @return iterable<string, array{ProxyConfigModel}> */
    public static function proxyEdits(): iterable
    {
        yield 'type' => [new ProxyConfigModel(ProxyType::Http, 'proxy.test', 1080, 'bob', 'pw', true, false)];
        yield 'host' => [new ProxyConfigModel(ProxyType::Socks5, 'other.test', 1080, 'bob', 'pw', true, false)];
        yield 'port' => [new ProxyConfigModel(ProxyType::Socks5, 'proxy.test', 1081, 'bob', 'pw', true, false)];
        yield 'username' => [new ProxyConfigModel(ProxyType::Socks5, 'proxy.test', 1080, 'eve', 'pw', true, false)];
        yield 'password' => [new ProxyConfigModel(ProxyType::Socks5, 'proxy.test', 1080, 'bob', 'new-pw', true, false)];
        yield 'remote DNS' => [new ProxyConfigModel(ProxyType::Socks5, 'proxy.test', 1080, 'bob', 'pw', true, true)];
    }

    public function testWithoutARowItBuildsFromTheFallbackDsn(): void
    {
        $transport = self::getContainer()->get(DynamicMailTransport::class);

        self::assertSame('null://', (string) $transport->activeTransport());
    }

    public function testWithARowItBuildsAnSmtpTransport(): void
    {
        self::getContainer()->get(MailSettings::class)->update(
            SettingsRequests::mail(host: 'smtp.relay.test', port: 2525, password: 'p')->toUpdate(),
        );
        $transport = self::getContainer()->get(DynamicMailTransport::class);

        self::assertInstanceOf(EsmtpTransport::class, $transport->activeTransport());
    }

    public function testTheBuiltTransportIsReusedWhileTheSettingsAreUnchanged(): void
    {
        $transport = self::getContainer()->get(DynamicMailTransport::class);

        self::assertSame($transport->activeTransport(), $transport->activeTransport());
    }

    public function testASettingsChangeRebuildsTheTransport(): void
    {
        $settings = self::getContainer()->get(MailSettings::class);
        $transport = self::getContainer()->get(DynamicMailTransport::class);
        $fallback = $transport->activeTransport();

        $settings->update(SettingsRequests::mail(host: 'smtp.relay.test', port: 2525, password: 'p')->toUpdate());
        $first = $transport->activeTransport();
        $settings->update(SettingsRequests::mail(host: 'smtp.relay.test', port: 2526, password: null)->toUpdate());
        $second = $transport->activeTransport();

        self::assertNotSame($fallback, $first);
        self::assertNotSame($first, $second);
        self::assertSame($second, $transport->activeTransport());
    }

    public function testAnUnreadableStoredPasswordSurfacesAsATransportFailure(): void
    {
        $row = new MailServerSettings();
        $row->apply(
            new MailConnection(true, 'smtp.relay.test', 587, 'alice', MailEncryption::Starttls, '', ''),
            new SealedSecret('not base64!', 'bm9uY2U=', 'c2FsdA==', 1),
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($row);
        $entityManager->flush();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage(
            'The stored mail password is unreadable: Stored secret material is not valid base64.',
        );
        self::getContainer()->get(DynamicMailTransport::class)->activeTransport();
    }

    public function testAnUnreadableStoredPasswordCarriesNoErrorCode(): void
    {
        $row = new MailServerSettings();
        $row->apply(
            new MailConnection(true, 'smtp.relay.test', 587, 'alice', MailEncryption::Starttls, '', ''),
            new SealedSecret('not base64!', 'bm9uY2U=', 'c2FsdA==', 1),
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($row);
        $entityManager->flush();

        try {
            self::getContainer()->get(DynamicMailTransport::class)->activeTransport();
            self::fail(TransportException::class . ' was not thrown.');
        } catch (TransportException $exception) {
            self::assertSame(0, $exception->getCode());
        }
    }

    public function testItNamesItselfAsTheDynamicDsn(): void
    {
        self::assertSame(
            'dynamic://default',
            (string) self::getContainer()->get(DynamicMailTransport::class),
        );
    }

    public function testActiveTransportUsesTheCurlTransportForAProxiedRow(): void
    {
        $this->configureAProxy();
        self::getContainer()->get(MailSettings::class)->update(SettingsRequests::mail(
            host: 'smtp.gmail.com',
            username: 'alice',
            password: 'app-pw',
            useProxy: true,
        )->toUpdate());

        $transport = self::getContainer()->get(DynamicMailTransport::class);

        self::assertInstanceOf(CurlSmtpTransport::class, $transport->activeTransport());
    }

    public function testAProxiedRowWhoseProxyIsGoneSurfacesAsATransportFailure(): void
    {
        // A row saved with use_proxy set while a proxy existed, then the proxy
        // config removed -- persisted directly because update() would refuse it.
        $cipher = self::getContainer()->get(MailPasswordCipher::class);
        $row = new MailServerSettings();
        $row->apply(
            new MailConnection(true, 'smtp.gmail.com', 587, 'alice', MailEncryption::Starttls, '', '', true),
            $cipher->seal('app-pw'),
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($row);
        $entityManager->flush();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage(
            'The mail configuration is incomplete: Mail is set to use the egress proxy, but no proxy is configured.',
        );
        self::getContainer()->get(DynamicMailTransport::class)->activeTransport();
    }

    public function testAProxiedRowWhoseProxyPasswordIsUnreadableSurfacesAsATransportFailure(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(UnreadableProxyPasswordRows::disabledWithUnreadablePassword());
        $row = new MailServerSettings();
        $row->apply(
            new MailConnection(true, 'smtp.gmail.com', 587, 'alice', MailEncryption::Starttls, '', '', true),
            self::getContainer()->get(MailPasswordCipher::class)->seal('app-pw'),
        );
        $entityManager->persist($row);
        $entityManager->flush();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage(
            'The stored proxy password is unreadable: Stored secret material is not valid base64.',
        );
        self::getContainer()->get(DynamicMailTransport::class)->activeTransport();
    }

    public function testActiveTransportUsesEsmtpForADirectRow(): void
    {
        self::getContainer()->get(MailSettings::class)->update(SettingsRequests::mail(
            host: 'smtp.relay.test',
            password: 'p',
            useProxy: false,
        )->toUpdate());

        $transport = self::getContainer()->get(DynamicMailTransport::class);

        self::assertInstanceOf(EsmtpTransport::class, $transport->activeTransport());
    }

    #[DataProvider('proxyEdits')]
    public function testAProxyEditRebuildsTheProxiedTransportOnTheNextSend(ProxyConfigModel $edited): void
    {
        $this->configuredProxy = self::originalProxy();
        $transport = $this->proxiedTransport();
        $beforeTheEdit = $transport->activeTransport();

        $this->configuredProxy = $edited;

        self::assertNotSame($beforeTheEdit, $transport->activeTransport());
    }

    public function testAnUnchangedProxyKeepsTheProxiedTransport(): void
    {
        $this->configuredProxy = self::originalProxy();
        $transport = $this->proxiedTransport();
        $first = $transport->activeTransport();

        $this->configuredProxy = self::originalProxy();

        self::assertSame($first, $transport->activeTransport());
    }

    public function testADeletedProxyFailsTheNextSendOfAProxiedRow(): void
    {
        $this->configuredProxy = self::originalProxy();
        $transport = $this->proxiedTransport();
        $transport->activeTransport();

        $this->configuredProxy = null;

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage(
            'The mail configuration is incomplete: Mail is set to use the egress proxy, but no proxy is configured.',
        );
        $transport->activeTransport();
    }

    private static function originalProxy(): ProxyConfigModel
    {
        return new ProxyConfigModel(ProxyType::Socks5, 'proxy.test', 1080, 'bob', 'pw', true, false);
    }

    private function proxiedTransport(): DynamicMailTransport
    {
        $settings = $this->createStub(MailSendingSettingsInterface::class);
        $settings->method('configuredTransport')->willReturn(
            new ResolvedMailTransportModel('smtp.gmail.com', 587, 'alice', 'app-pw', MailEncryption::Starttls, true),
        );
        $proxySource = $this->createStub(ConfiguredProxySourceInterface::class);
        $proxySource->method('configuredProxy')->willReturnCallback(fn (): ?ProxyConfigModel => $this->configuredProxy);
        $factory = new ActiveMailTransportFactory(
            $proxySource,
            $this->createStub(HttpClientInterface::class),
            new EsmtpTransportFactory(),
        );

        return new DynamicMailTransport(
            $settings,
            $factory,
            $this->createStub(EventDispatcherInterface::class),
            new NullLogger(),
        );
    }
}
