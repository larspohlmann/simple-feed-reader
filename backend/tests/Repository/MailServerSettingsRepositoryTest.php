<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\MailConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailServerSettingsRepositoryTest extends KernelTestCase
{
    public function testTheSingletonIsTheOldestRowWhenMoreThanOneExists(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($this->rowFor('first.test'));
        $em->flush();
        $em->persist($this->rowFor('second.test'));
        $em->flush();

        $singleton = self::getContainer()->get(MailServerSettingsRepository::class)->findSingleton();

        self::assertSame('first.test', $singleton?->getHost());
    }

    private function rowFor(string $host): MailServerSettings
    {
        $settings = new MailServerSettings();
        $settings->applyWithoutPassword(
            new MailConnection(false, $host, 587, null, MailEncryption::Starttls, '', ''),
        );

        return $settings;
    }
}
