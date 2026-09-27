<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\MailConnection;
use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seeds an enabled `mail_server_settings` row with a blank host, so sending reports "on" while mail still falls
 * through to the null:// fallback transport. Do not use this where "no mail row" is the state under test.
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
