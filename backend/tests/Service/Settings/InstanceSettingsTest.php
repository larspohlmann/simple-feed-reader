<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Entity\InstanceSetting;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
use App\Tests\Support\QueryRecorder;
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
     * The entity is the single source of truth for what a setting means when
     * nobody has set it: every InstanceSettings getter, with no row present,
     * must return exactly what a freshly constructed InstanceSetting reports
     * on its own. This fails if a getter ever grows its own `??` fallback
     * that disagrees with the entity's property default.
     *
     * Reflects over InstanceSettings' own zero-argument public methods
     * (fix round 2) rather than naming each getter by hand: a hand-enumerated
     * list catches an EXISTING getter regaining a drifting fallback, but a
     * NEW setting added with its own literal default would add a getter this
     * test never calls, and pass silently — precisely the drift this test
     * exists to catch. update() is the only public method with a required
     * parameter, so filtering on parameter count excludes it without naming
     * it specifically.
     */
    public function testEveryGetterMatchesAFreshInstanceSettingWhenNoRowExists(): void
    {
        $fresh = new InstanceSetting();
        $reflection = new \ReflectionClass(InstanceSettings::class);
        $getters = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $method): bool => !$method->isStatic()
                && !$method->isConstructor()
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

    /**
     * Deliberately false (#624 follow-up, addendum — the product owner
     * reversed the original `true` default): "activated" should mean
     * activated, so a fresh install ships with passkey sign-in invisible
     * until an admin opts in, even though the relying party would derive
     * correctly with no configuration at all.
     */
    public function testPasskeySignInDefaultsToDisabledWhenNoRowExists(): void
    {
        self::assertFalse($this->settings->passkeySignInEnabled());
    }

    /**
     * Sets it to TRUE and reads TRUE back, deliberately — not false: the
     * no-row default is ALSO false now, so a round trip that merely sets and
     * reads false back would pass even if update()/apply() silently did
     * nothing at all. Setting the non-default value is what actually proves
     * persistence.
     */
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
        $this->em->clear();

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

    /**
     * The row is resolved once per request, not once per getter: reading five
     * settings in a row must issue a single SELECT. This is the whole reason
     * the memo exists (#725) — a WebAuthn ceremony reads the row three or four
     * times, and each read used to be its own round trip.
     */
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

    /**
     * update() must drop the memo, so the admin who just saved reads back their
     * own new value in the same request — not the row memoised before the save.
     * Deliberately no em->clear() here: clearing would hide a missing memo
     * invalidation. This test is what stops a future change reintroducing the
     * stale read (#725).
     */
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
        $this->em->clear();

        $count = (int) $this->em
            ->createQuery('SELECT COUNT(s.id) FROM App\Entity\InstanceSetting s')
            ->getSingleScalarResult();
        self::assertSame(1, $count);
        self::assertTrue($this->settings->requireEmailConfirmation());
        self::assertFalse($this->settings->requireApproval());
    }
}
