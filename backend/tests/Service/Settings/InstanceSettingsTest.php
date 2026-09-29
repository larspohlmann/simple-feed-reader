<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Entity\InstanceSetting;
use App\Entity\InstanceSettingsUpdate;
use App\Service\Settings\InstanceSettings;
use App\Tests\Support\QueryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstanceSettingsTest extends KernelTestCase
{
    private InstanceSettings $settings;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->settings = $container->get(InstanceSettings::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testDefaultsToBothGatesOnWhenNoRowExists(): void
    {
        self::assertTrue($this->settings->requireEmailConfirmation());
        self::assertTrue($this->settings->requireApproval());
    }

    /**
     * With no row, every getter must match a fresh InstanceSetting, so none grows its own `??` default. It reflects
     * over the zero-argument public methods, so a new getter is covered without being named here.
     */
    public function testEveryGetterMatchesAFreshInstanceSettingWhenNoRowExists(): void
    {
        $fresh = new InstanceSetting();
        $reflection = new \ReflectionClass(InstanceSettings::class);
        $getters = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $method): bool => !$method->isStatic()
                && !$method->isConstructor()
                && 'void' !== (string) $method->getReturnType()
                && 0 === $method->getNumberOfParameters(),
        );
        self::assertNotEmpty($getters, 'Expected InstanceSettings to expose at least one getter.');

        foreach ($getters as $getter) {
            $name = $getter->getName();
            self::assertSame(
                $fresh->$name(),
                $this->settings->$name(),
                \sprintf(
                    'InstanceSettings::%s() disagrees with a fresh InstanceSetting when no row exists.',
                    $name,
                ),
            );
        }
    }

    /** Off until an admin opts in, although the relying party would derive correctly with no configuration. */
    public function testPasskeySignInDefaultsToDisabledWhenNoRowExists(): void
    {
        self::assertFalse($this->settings->passkeySignInEnabled());
    }

    /** Sets the non-default TRUE: a round trip of false would pass even if update() did nothing. */
    public function testPasskeySignInEnabledRoundTrips(): void
    {
        $this->settings->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: null,
            passkeyRpId: null,
            passkeyRpName: null,
            passkeySignInEnabled: true,
        ));
        $this->entityManager->clear();

        self::assertTrue($this->settings->passkeySignInEnabled());
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
        $this->entityManager->clear();

        self::assertFalse($this->settings->requireEmailConfirmation());
        self::assertTrue($this->settings->requireApproval());
    }

    public function testPublicBaseUrlDefaultsToNullAndRoundTrips(): void
    {
        self::assertNull($this->settings->getPublicBaseUrl());

        $this->settings->update(
            new InstanceSettingsUpdate(true, true, 'https://reader.example.ts.net/reader', null, null),
        );
        $this->entityManager->clear();

        self::assertSame('https://reader.example.ts.net/reader', $this->settings->getPublicBaseUrl());
    }

    public function testPasskeyRelyingPartyOverridesDefaultToNullAndRoundTrip(): void
    {
        self::assertNull($this->settings->getPasskeyRpId());
        self::assertNull($this->settings->getPasskeyRpName());

        $this->settings->update(new InstanceSettingsUpdate(true, true, null, 'example.test', 'My Reader'));
        $this->entityManager->clear();

        self::assertSame('example.test', $this->settings->getPasskeyRpId());
        self::assertSame('My Reader', $this->settings->getPasskeyRpName());
    }

    public function testResolvesTheRowOnceAcrossSeveralGetters(): void
    {
        /** @var QueryRecorder $recorder */
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        $recorder->reset();

        $this->settings->requireEmailConfirmation();
        $this->settings->requireApproval();
        $this->settings->getPublicBaseUrl();
        $this->settings->getPasskeyRpId();
        $this->settings->passkeySignInEnabled();

        $reads = $recorder->queriesMatching('from instance_setting');
        self::assertCount(
            1,
            $reads,
            "Five getters must share one resolved row, got:\n" . implode("\n", $reads),
        );
    }

    /** No em->clear() on purpose: clearing would hide a missing memo invalidation. */
    public function testReadAfterWriteInTheSameRequestReturnsTheNewValue(): void
    {
        self::assertTrue($this->settings->requireEmailConfirmation());

        $this->settings->update(new InstanceSettingsUpdate(false, true, null, null, null));

        self::assertFalse($this->settings->requireEmailConfirmation());
    }

    public function testUpdateReusesTheSingleRowRatherThanInsertingASecond(): void
    {
        $this->settings->update(new InstanceSettingsUpdate(false, false, null, null, null));
        $this->settings->update(new InstanceSettingsUpdate(true, false, null, null, null));
        $this->entityManager->clear();

        $count = (int) $this->entityManager
            ->createQuery('SELECT COUNT(s.id) FROM App\Entity\InstanceSetting s')
            ->getSingleScalarResult();
        self::assertSame(1, $count);
        self::assertTrue($this->settings->requireEmailConfirmation());
        self::assertFalse($this->settings->requireApproval());
    }
}
