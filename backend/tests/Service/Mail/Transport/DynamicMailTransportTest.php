<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Entity\MailConnection;
use App\Entity\MailServerSettings;
use App\Entity\SealedSecret;
use App\Enum\MailEncryption;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\MailSettings;
use App\Service\Mail\Transport\CurlSmtpTransport;
use App\Service\Mail\Transport\DynamicMailTransport;
use App\Tests\Support\ConfiguresAProxy;
use App\Tests\Support\SettingsRequests;
use App\Tests\Support\UnreadableProxyPasswordRows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class DynamicMailTransportTest extends KernelTestCase
{
    use ConfiguresAProxy;

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
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($row);
        $em->flush();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage(
            'The stored mail password is unreadable: Stored secret material is not valid base64.',
        );
        self::getContainer()->get(DynamicMailTransport::class)->activeTransport();
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
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($row);
        $em->flush();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage(
            'The mail configuration is incomplete: Mail is set to use the egress proxy, but no proxy is configured.',
        );
        self::getContainer()->get(DynamicMailTransport::class)->activeTransport();
    }

    public function testAProxiedRowWhoseProxyPasswordIsUnreadableSurfacesAsATransportFailure(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(UnreadableProxyPasswordRows::disabledWithUnreadablePassword());
        $row = new MailServerSettings();
        $row->apply(
            new MailConnection(true, 'smtp.gmail.com', 587, 'alice', MailEncryption::Starttls, '', '', true),
            self::getContainer()->get(MailPasswordCipher::class)->seal('app-pw'),
        );
        $em->persist($row);
        $em->flush();

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
}
