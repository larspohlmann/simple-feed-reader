<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\GrafanaSettings;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\GrafanaConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GrafanaSettingsRepositoryTest extends KernelTestCase
{
    public function testTheSingletonIsTheOldestRowWhenMoreThanOneExists(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($this->rowFor('https://first.example'));
        $em->flush();
        $em->persist($this->rowFor('https://second.example'));
        $em->flush();

        $singleton = self::getContainer()->get(GrafanaSettingsRepository::class)->findSingleton();

        self::assertSame('https://first.example', $singleton?->getGrafanaUrlOverride());
    }

    private function rowFor(string $grafanaUrl): GrafanaSettings
    {
        $settings = new GrafanaSettings();
        $settings->applyWithoutToken(new GrafanaConnection(null, null, $grafanaUrl, null, false));

        return $settings;
    }
}
