<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\InstanceSettingsUpdate;
use App\Service\Settings\InstanceSettings;

/**
 * Sets the instance-wide passkey switch and leaves every other setting at its default. That suffices both ways: the
 * guard checks the toggle first, and `localhost` is a valid derived relying party. For a specific one, use
 * PinsPasskeyRelyingParty.
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
