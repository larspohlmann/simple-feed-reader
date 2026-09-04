<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Dto\Admin\MailSettingsRequest;
use App\Service\Mail\Settings\MailSettings;
use App\Service\Mail\Transport\DynamicMailTransport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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

    public function testItNamesItselfAsTheDynamicDsn(): void
    {
        self::assertSame(
            'dynamic://default',
            (string) self::getContainer()->get(DynamicMailTransport::class),
        );
    }
}
