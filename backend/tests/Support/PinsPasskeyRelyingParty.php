<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\InstanceSettingsUpdate;
use App\Service\Settings\InstanceSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Pins the relying party and the origin to the values a test's fixtures were built for, never APP_FRONTEND_URL, and
 * enables sign-in explicitly, as the instance default is off. For the disabled case, use TogglesPasskeySignIn.
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
