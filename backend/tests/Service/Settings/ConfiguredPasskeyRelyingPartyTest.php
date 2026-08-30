<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Service\Settings\ConfiguredPasskeyRelyingParty;
use App\Service\Settings\EffectivePasskeyRelyingPartyId;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
use App\Service\Settings\ServingHost;
use App\Tests\Support\FixedPublicBaseUrl;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * InstanceSettings is `final readonly`, so PHPUnit cannot double it — the
 * same constraint InstanceSettingsTest and RegistrationPolicyTest already
 * work around. We boot the kernel and drive the real service instead.
 */
final class ConfiguredPasskeyRelyingPartyTest extends KernelTestCase
{
    private const string EMAIL_LINK_URL = 'https://mail-links.example.com/reader';

    private InstanceSettings $settings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->settings = self::getContainer()->get(InstanceSettings::class);
    }

    public function testTheRelyingPartyIdDefaultsToTheServingHost(): void
    {
        $relyingParty = $this->relyingParty(
            configuredId: null,
            servedFrom: 'https://reader.example.com/reader',
        );

        self::assertSame('reader.example.com', $relyingParty->id());
        self::assertSame('Simple Feed Reader', $relyingParty->name());
    }

    public function testAConfiguredRelyingPartyIdWins(): void
    {
        $relyingParty = $this->relyingParty(
            configuredId: 'example.test',
            servedFrom: 'https://reader.example.com/reader',
            configuredName: 'My Reader',
        );

        self::assertSame('example.test', $relyingParty->id());
        self::assertSame('My Reader', $relyingParty->name());
    }

    /**
     * The decoupling itself: the public base URL is where email links point,
     * and moving it must not move the relying party — an id change orphans
     * every enrolled passkey.
     */
    public function testThePublicBaseUrlDoesNotMoveTheRelyingParty(): void
    {
        $servedFrom = 'https://reader.example.com/reader';

        self::assertSame(
            $this->relyingParty(null, $servedFrom, emailLinkUrl: 'https://one.example.org')->id(),
            $this->relyingParty(null, $servedFrom, emailLinkUrl: 'https://two.example.org')->id(),
        );
    }

    /** A relying-party id carries no port or subpath, only the host. */
    public function testThePortAndSubpathAreAbsentFromTheDerivedId(): void
    {
        self::assertSame(
            'localhost',
            $this->relyingParty(null, 'http://localhost:4200/reader')->id(),
        );
    }

    /** With no request at all — a CLI run — the public base URL's host stands in. */
    public function testTheServingHostFallsBackToThePublicBaseUrlWithoutARequest(): void
    {
        $relyingParty = new ConfiguredPasskeyRelyingParty(
            $this->settingsReturning(null, null),
            new ServingHost(new RequestStack(), new FixedPublicBaseUrl(self::EMAIL_LINK_URL)),
            new EffectivePasskeyRelyingPartyId(),
        );

        self::assertSame('mail-links.example.com', $relyingParty->id());
    }

    private function relyingParty(
        ?string $configuredId,
        string $servedFrom,
        ?string $configuredName = null,
        string $emailLinkUrl = self::EMAIL_LINK_URL,
    ): ConfiguredPasskeyRelyingParty {
        $requests = new RequestStack();
        $requests->push(Request::create($servedFrom));

        return new ConfiguredPasskeyRelyingParty(
            $this->settingsReturning($configuredId, $configuredName),
            new ServingHost($requests, new FixedPublicBaseUrl($emailLinkUrl)),
            new EffectivePasskeyRelyingPartyId(),
        );
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
}
