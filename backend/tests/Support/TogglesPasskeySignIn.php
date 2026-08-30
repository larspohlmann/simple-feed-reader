<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;

/**
 * Sets the instance-wide passkey sign-in switch, leaving every other setting
 * at its default (#624 follow-up, fix round 1 — renamed from
 * DisablesPasskeySignIn once the product owner's addendum flipped the
 * default to off and every test exercising an endpoint that does not care
 * about the relying party needed the ON half too).
 *
 * `disablePasskeySignIn()` was lifted out of near-identical private methods
 * that had already accumulated in PasskeyListTest and PasskeyLoginTest, plus
 * inline duplicates in PasskeyLoginOptionsTest, SetupControllerTest and
 * PasskeySignInAvailabilityTest — CLAUDE.md's DRY rule treats a third
 * occurrence as a refactor, not a copy.
 *
 * Leaving passkeyRpId/passkeyRpName/publicBaseUrl at their defaults in BOTH
 * methods is deliberate, not an oversight. For disable, it is moot:
 * `PasskeySignInAvailability::guard()` checks the toggle FIRST and
 * short-circuits before the relying-party validity check ever runs, so a
 * caller proving "disabled refuses this endpoint" never needs a working
 * relying party to prove it. For enable, the derived default (the public
 * base URL's host, `localhost` in the test environment) is already a valid
 * relying party on its own — see RelyingPartyIdRule — so a caller that only
 * needs "enabled, with SOME working configuration" can use this rather than
 * pinning one explicitly. A test that also needs a SPECIFIC relying party
 * (a real WebAuthn ceremony against captured fixtures) uses
 * PinsPasskeyRelyingParty instead, which sets `passkeySignInEnabled: true`
 * itself.
 */
trait TogglesPasskeySignIn
{
    private function enablePasskeySignIn(): void
    {
        $this->setPasskeySignInEnabled(true);
    }

    private function disablePasskeySignIn(): void
    {
        $this->setPasskeySignInEnabled(false);
    }

    private function setPasskeySignInEnabled(bool $enabled): void
    {
        /** @var InstanceSettings $settings */
        $settings = self::getContainer()->get(InstanceSettings::class);
        $settings->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: null,
            passkeyRpId: null,
            passkeyRpName: null,
            passkeySignInEnabled: $enabled,
        ));
    }
}
