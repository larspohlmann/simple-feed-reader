<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Service\Settings\ConfiguredPasskeyRelyingParty;
use App\Service\Settings\EffectivePasskeyRelyingPartyId;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
use App\Service\Settings\PublicBaseUrl;
use App\Tests\Support\FixedPublicBaseUrl;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * InstanceSettings is `final readonly`, so PHPUnit cannot double it — the
 * same constraint InstanceSettingsTest and RegistrationPolicyTest already
 * work around. We boot the kernel and drive the real service instead.
 */
final class ConfiguredPasskeyRelyingPartyTest extends KernelTestCase
{
    private InstanceSettings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(InstanceSettings::class);
    }

    private function settingsReturning(?string $passkeyRpId, ?string $passkeyRpName): InstanceSettings
    {
        $this->settings->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: null,
            passkeyRpId: $passkeyRpId,
            passkeyRpName: $passkeyRpName,
        ));

        return $this->settings;
    }

    private function publicBaseUrlOf(string $url): PublicBaseUrl
    {
        return new FixedPublicBaseUrl($url);
    }

    public function testTheRelyingPartyIdDefaultsToThePublicBaseUrlHost(): void
    {
        $relyingParty = new ConfiguredPasskeyRelyingParty(
            $this->settingsReturning(passkeyRpId: null, passkeyRpName: null),
            $this->publicBaseUrlOf('https://lars-pohlmann.de/reader'),
            new EffectivePasskeyRelyingPartyId(),
        );

        self::assertSame('lars-pohlmann.de', $relyingParty->id());
        self::assertSame('Simple Feed Reader', $relyingParty->name());
    }

    public function testAConfiguredRelyingPartyIdWins(): void
    {
        $relyingParty = new ConfiguredPasskeyRelyingParty(
            $this->settingsReturning(passkeyRpId: 'example.test', passkeyRpName: 'My Reader'),
            $this->publicBaseUrlOf('https://lars-pohlmann.de/reader'),
            new EffectivePasskeyRelyingPartyId(),
        );

        self::assertSame('example.test', $relyingParty->id());
        self::assertSame('My Reader', $relyingParty->name());
    }

    /** The /reader subpath is irrelevant to a relying-party id — spec §3.6. */
    public function testTheSubpathIsStrippedFromTheDerivedId(): void
    {
        $relyingParty = new ConfiguredPasskeyRelyingParty(
            $this->settingsReturning(passkeyRpId: null, passkeyRpName: null),
            $this->publicBaseUrlOf('http://localhost:4200/reader'),
            new EffectivePasskeyRelyingPartyId(),
        );

        self::assertSame('localhost', $relyingParty->id());
    }
}
