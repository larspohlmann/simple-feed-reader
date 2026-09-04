<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Dto\Admin\MailSettingsRequest;
use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use App\Service\Mail\Settings\Crypto\SealedMailPassword;
use App\Service\Mail\Settings\MailConnection;
use App\Service\Mail\Settings\MailSettings;
use App\Service\Mail\Transport\DynamicMailTransport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class DynamicMailTransportTest extends KernelTestCase
{
    public function testWithoutARowItBuildsFromTheFallbackDsn(): void
    {
        $transport = self::getContainer()->get(DynamicMailTransport::class);

        self::assertSame('null://', (string) $transport->activeTransport());
    }

    public function testWithARowItBuildsAnSmtpTransport(): void
    {
        self::getContainer()->get(MailSettings::class)->update(
            new MailSettingsRequest(host: 'smtp.relay.test', port: 2525, password: 'p'),
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

        $settings->update(new MailSettingsRequest(host: 'smtp.relay.test', port: 2525, password: 'p'));
        $first = $transport->activeTransport();
        $settings->update(new MailSettingsRequest(host: 'smtp.relay.test', port: 2526, password: null));
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
            new SealedMailPassword('not base64!', 'bm9uY2U=', 'c2FsdA==', 1),
            'hint',
        );
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($row);
        $em->flush();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage(
            'The stored mail password is unreadable: Stored mail secret is not valid base64.',
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
}
