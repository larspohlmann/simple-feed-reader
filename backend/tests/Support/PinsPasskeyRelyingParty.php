<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Pins the WebAuthn relying party AND the public base URL (the origin) to
 * exact values a test's fixtures were built for, rather than relying on
 * whatever `APP_FRONTEND_URL` happens to resolve to in this environment.
 *
 * Lifted out of PasskeyRegistrationTest's own private pinRelyingParty()
 * (#624 Task 6) once a third caller (AssertionVerifierTest, PasskeyLoginTest
 * — #624 Task 10) needed the identical setup: CLAUDE.md's DRY rule treats a
 * third occurrence as a refactor, not a copy.
 *
 * Passes `passkeySignInEnabled: true` explicitly (#624 follow-up, addendum):
 * InstanceSettingsUpdate's own constructor default flipped to `false` when
 * the product owner reversed the instance default, so a caller of THIS
 * helper — whose whole point is "set up a working passkey configuration for
 * a ceremony test" — can no longer ride that default the way it could when
 * it meant "on". A test that wants the DISABLED case uses
 * TogglesPasskeySignIn instead.
 */
trait PinsPasskeyRelyingParty
{
    private function pinRelyingParty(
        string $relyingPartyId,
        string $relyingPartyName,
        ?string $publicBaseUrl = null,
    ): void {
        /** @var InstanceSettings $settings */
        $settings = self::getContainer()->get(InstanceSettings::class);
        $settings->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: $publicBaseUrl,
            passkeyRpId: $relyingPartyId,
            passkeyRpName: $relyingPartyName,
            passkeySignInEnabled: true,
        ));
    }

    /** The relying party is judged against the host the request arrived on, so
     *  the client must come from the pinned origin, as a real browser would. */
    private function serveFrom(KernelBrowser $client, string $publicBaseUrl): void
    {
        $host = parse_url($publicBaseUrl, PHP_URL_HOST);
        $client->setServerParameter('HTTP_HOST', \is_string($host) ? $host : 'localhost');
    }
}
