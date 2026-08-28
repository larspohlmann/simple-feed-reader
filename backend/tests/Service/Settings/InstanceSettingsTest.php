<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Service\Settings\InstanceSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstanceSettingsTest extends KernelTestCase
{
    private InstanceSettings $settings;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->settings = $container->get(InstanceSettings::class);
        $this->em = $container->get(EntityManagerInterface::class);
    }

    public function testDefaultsToBothGatesOnWhenNoRowExists(): void
    {
        self::assertTrue($this->settings->requireEmailConfirmation());
        self::assertTrue($this->settings->requireApproval());
    }

    public function testUpdatePersistsAndIsReadBack(): void
    {
        $this->settings->update(requireEmailConfirmation: false, requireApproval: true, publicBaseUrl: null);
        $this->em->clear();

        self::assertFalse($this->settings->requireEmailConfirmation());
        self::assertTrue($this->settings->requireApproval());
    }

    public function testPublicBaseUrlDefaultsToNullAndRoundTrips(): void
    {
        self::assertNull($this->settings->getPublicBaseUrl());

        $this->settings->update(true, true, 'https://reader.example.ts.net/reader');
        $this->em->clear();

        self::assertSame('https://reader.example.ts.net/reader', $this->settings->getPublicBaseUrl());
    }

    public function testUpdateReusesTheSingleRowRatherThanInsertingASecond(): void
    {
        $this->settings->update(false, false, null);
        $this->settings->update(true, false, null);
        $this->em->clear();

        $count = (int) $this->em
            ->createQuery('SELECT COUNT(s.id) FROM App\Entity\InstanceSetting s')
            ->getSingleScalarResult();
        self::assertSame(1, $count);
        self::assertTrue($this->settings->requireEmailConfirmation());
        self::assertFalse($this->settings->requireApproval());
    }
}
