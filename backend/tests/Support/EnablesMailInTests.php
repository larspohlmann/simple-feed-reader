<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use App\Service\Mail\Settings\MailConnection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seeds a `mail_server_settings` row with `enabled = true` and a blank host,
 * so MailSettings::isSendingEnabled() reports true while configuredTransport()
 * stays null and mail falls through to the null:// fallback transport, which
 * collects messages instead of sending them.
 *
 * Only call this from a functional test that needs mail on by default; leave
 * it out where no row (the null fallback) is the state under test, such as
 * MailSettingsTest and DynamicMailTransportTest.
 */
trait EnablesMailInTests
{
    protected function seedEnabledMailInstance(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $settings = new MailServerSettings();
        $settings->applyWithoutPassword(new MailConnection(
            true,
            '',
            MailConnection::DEFAULT_PORT,
            null,
            MailEncryption::Starttls,
            '',
            '',
        ));

        $em->persist($settings);
        $em->flush();
    }
}
