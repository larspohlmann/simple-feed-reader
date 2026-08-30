<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Entity\UserPasskey;
use App\Service\Passkey\Exception\PasskeySignInDisabledException;
use App\Service\Passkey\PasskeySignInAvailability;
use App\Service\Settings\EffectivePasskeyRelyingPartyId;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
use App\Service\Settings\PasskeyRelyingParty;
use App\Service\Settings\PublicBaseUrl;
use App\Service\Settings\RelyingPartyIdRule;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TogglesPasskeySignIn;
use App\Tests\Support\FixedPublicBaseUrl;

/**
 * Whether passkey sign-in may be offered at all (#624 follow-up) — the toggle
 * AND the relying-party id both have to hold, and neither the toggle nor the
 * id check ever touches a credential or an account row: see
 * testTheAnswerNeverVariesWithHowManyCredentialsOrAccountsExist for the
 * no-enumeration guarantee this class exists to keep.
 *
 * A KernelTestCase, not a plain unit test, because InstanceSettings is
 * `final readonly` and cannot be doubled — ConfiguredPasskeyRelyingPartyTest
 * hits the identical constraint and works around it the same way.
 */
final class PasskeySignInAvailabilityTest extends ApiTestCase
{
    use TogglesPasskeySignIn;

    private InstanceSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->settings = self::getContainer()->get(InstanceSettings::class);
    }

    /**
     * Off by default (#624 follow-up, addendum): a fresh instance with no
     * row, and no explicit enable call, must read as unavailable even with a
     * perfectly valid relying party.
     */
    public function testUnavailableByDefault(): void
    {
        $availability = $this->availabilityFor('example.test', 'https://example.test');

        self::assertFalse($availability->isAvailable());
    }

    public function testAvailableOnceEnabledWithAValidRelyingParty(): void
    {
        $this->enablePasskeySignIn();
        $availability = $this->availabilityFor('example.test', 'https://example.test');

        self::assertTrue($availability->isAvailable());
    }

    public function testUnavailableWhenTheToggleIsOff(): void
    {
        $this->disablePasskeySignIn();
        $availability = $this->availabilityFor('example.test', 'https://example.test');

        self::assertFalse($availability->isAvailable());
    }

    /** The toggle is on, but the configured relying-party id fails the suffix rule. */
    public function testUnavailableWhenTheRelyingPartyIdIsNotValidForTheHost(): void
    {
        $this->enablePasskeySignIn();
        $availability = $this->availabilityFor('evil.test', 'https://example.test');

        self::assertFalse($availability->isAvailable());
    }

    /**
     * No enumeration: the flag is derived purely from instance configuration.
     * Enrolling real credentials for real accounts must never move it.
     */
    public function testTheAnswerNeverVariesWithHowManyCredentialsOrAccountsExist(): void
    {
        $this->enablePasskeySignIn();
        $availability = $this->availabilityFor('example.test', 'https://example.test');
        self::assertTrue($availability->isAvailable());

        $owner = $this->factory()->create('passkey-owner@example.test');
        $this->em()->persist(new UserPasskey(
            $owner,
            credentialId: bin2hex(random_bytes(16)),
            userHandle: bin2hex(random_bytes(16)),
            publicKey: 'test-public-key',
            signatureCounter: 0,
            aaguid: null,
            transports: [],
            label: 'Test passkey',
            createdAt: new \DateTimeImmutable('2026-08-29 10:00:00'),
        ));
        $this->em()->flush();
        $this->factory()->create('another-user@example.test');

        self::assertTrue($this->availabilityFor('example.test', 'https://example.test')->isAvailable());
    }

    public function testGuardIsANoOpWhenAvailable(): void
    {
        $this->enablePasskeySignIn();
        $availability = $this->availabilityFor('example.test', 'https://example.test');
        $this->expectNotToPerformAssertions();

        $availability->guard();
    }

    public function testGuardThrowsWhenUnavailable(): void
    {
        $this->disablePasskeySignIn();
        $availability = $this->availabilityFor('example.test', 'https://example.test');

        $this->expectException(PasskeySignInDisabledException::class);

        $availability->guard();
    }

    /** The mirror of TogglesPasskeySignIn's trait method, local to this file:
     *  no other suite needs "toggle on, everything else default" as its own
     *  reusable step, so this one stays private rather than becoming a
     *  second Support trait for a single caller. */
    private function enablePasskeySignIn(): void
    {
        $this->settings->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: null,
            passkeyRpId: null,
            passkeyRpName: null,
            passkeySignInEnabled: true,
        ));
    }

    private function availabilityFor(string $relyingPartyId, string $publicBaseUrl): PasskeySignInAvailability
    {
        return new PasskeySignInAvailability(
            $this->settings,
            new FixedPublicBaseUrl($publicBaseUrl),
            $this->relyingPartyOf($relyingPartyId),
            new RelyingPartyIdRule(),
            new EffectivePasskeyRelyingPartyId(),
        );
    }

    private function relyingPartyOf(string $id): PasskeyRelyingParty
    {
        return new class ($id) implements PasskeyRelyingParty {
            public function __construct(private string $id)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function name(): string
            {
                return 'Simple Feed Reader';
            }
        };
    }
}
