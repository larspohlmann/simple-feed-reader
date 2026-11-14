<?php

declare(strict_types=1);

namespace App\Service\Identity\Passkey;

use App\Service\Identity\Passkey\Exception\PasskeySignInDisabledException;
use App\Service\Operations\Settings\InstanceSettings;
use App\Service\Operations\Settings\PasskeyRelyingParty\PasskeyRelyingPartyInterface;
use App\Service\Operations\Settings\RelyingPartyIdRule;

/**
 * Whether passkey sign-in may be offered: the admin toggle is on and the relying-party id could work at all (never
 * matched against a host this server guesses). Reads instance configuration only, so it enumerates nothing.
 */
final readonly class PasskeySignInAvailability
{
    public function __construct(
        private InstanceSettings $settings,
        private PasskeyRelyingPartyInterface $relyingParty,
        private RelyingPartyIdRule $relyingPartyIdRule,
    ) {
    }

    public function isAvailable(): bool
    {
        if (!$this->settings->passkeySignInEnabled()) {
            return false;
        }

        return $this->relyingPartyIdRule->isUsable($this->relyingParty->id());
    }

    /**
     * @throws PasskeySignInDisabledException when isAvailable() is false
     */
    public function guard(): void
    {
        if (!$this->isAvailable()) {
            throw new PasskeySignInDisabledException();
        }
    }
}
