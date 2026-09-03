<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Service\Passkey\Exception\PasskeySignInDisabledException;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\PasskeyRelyingParty;
use App\Service\Settings\RelyingPartyIdRule;

/**
 * Whether passkey sign-in may be offered at all — the question
 * `GET /api/setup/status` answers for an anonymous visitor as
 * `passkeySignInAvailable`, and what every passkey endpoint enforces
 * server-side rather than trusting the frontend to hide its own buttons.
 *
 * Available when BOTH hold: the admin toggle (`InstanceSetting::
 * passkeySignInEnabled`) is on, AND the configured relying-party id could work
 * at all — never whether it matches a host this server guessed at, which hid
 * passkey sign-in on every proxied deployment.
 *
 * Both checks read only instance-wide configuration, so the answer — and its
 * cost — cannot vary with how many accounts exist or how many passkeys are
 * enrolled, the same no-enumeration property AssertionOptionsFactory's
 * docblock describes for the login-options endpoint.
 */
final readonly class PasskeySignInAvailability
{
    public function __construct(
        private InstanceSettings $settings,
        private PasskeyRelyingParty $relyingParty,
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
