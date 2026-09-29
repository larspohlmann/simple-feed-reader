<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\MailConnection;
use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use App\Repository\MailServerSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MailServerSettingsRepositoryTest extends KernelTestCase
{
    public function testTheSingletonIsTheOldestRowWhenMoreThanOneExists(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($this->rowFor('first.test'));
        $entityManager->flush();
        $entityManager->persist($this->rowFor('second.test'));
        $entityManager->flush();

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
