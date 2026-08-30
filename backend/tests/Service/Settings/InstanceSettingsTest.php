<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
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

    /**
     * Deliberately true: the relying party derives correctly with no
     * configuration at all, so passkey sign-in works out of the box, and
     * defaulting it off would silently suppress the first-login enrolment
     * offer this branch exists to deliver (#624 follow-up).
     */
    public function testPasskeySignInDefaultsToEnabledWhenNoRowExists(): void
    {
        self::assertTrue($this->settings->passkeySignInEnabled());
    }

    public function testPasskeySignInEnabledRoundTrips(): void
    {
        $this->settings->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: null,
            passkeyRpId: null,
            passkeyRpName: null,
            passkeySignInEnabled: false,
        ));
        $this->em->clear();

        self::assertFalse($this->settings->passkeySignInEnabled());
    }

    public function testUpdatePersistsAndIsReadBack(): void
    {
        $this->settings->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: false,
            requireApproval: true,
            publicBaseUrl: null,
            passkeyRpId: null,
            passkeyRpName: null,
        ));
        $this->em->clear();

        self::assertFalse($this->settings->requireEmailConfirmation());
        self::assertTrue($this->settings->requireApproval());
    }

    public function testPublicBaseUrlDefaultsToNullAndRoundTrips(): void
    {
        self::assertNull($this->settings->getPublicBaseUrl());

        $this->settings->update(
            new InstanceSettingsUpdate(true, true, 'https://reader.example.ts.net/reader', null, null),
        );
        $this->em->clear();

        self::assertSame('https://reader.example.ts.net/reader', $this->settings->getPublicBaseUrl());
    }

    public function testPasskeyRelyingPartyOverridesDefaultToNullAndRoundTrip(): void
    {
        self::assertNull($this->settings->getPasskeyRpId());
        self::assertNull($this->settings->getPasskeyRpName());

        $this->settings->update(new InstanceSettingsUpdate(true, true, null, 'example.test', 'My Reader'));
        $this->em->clear();

        self::assertSame('example.test', $this->settings->getPasskeyRpId());
        self::assertSame('My Reader', $this->settings->getPasskeyRpName());
    }

    public function testUpdateReusesTheSingleRowRatherThanInsertingASecond(): void
    {
        $this->settings->update(new InstanceSettingsUpdate(false, false, null, null, null));
        $this->settings->update(new InstanceSettingsUpdate(true, false, null, null, null));
        $this->em->clear();

        $count = (int) $this->em
            ->createQuery('SELECT COUNT(s.id) FROM App\Entity\InstanceSetting s')
            ->getSingleScalarResult();
        self::assertSame(1, $count);
        self::assertTrue($this->settings->requireEmailConfirmation());
        self::assertFalse($this->settings->requireApproval());
    }
}
