<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Service\Passkey\Exception\PasskeySignInDisabledException;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\PasskeyRelyingParty;
use App\Service\Settings\RelyingPartyIdRule;
use App\Service\Settings\ServingHost;

/**
 * Whether passkey sign-in may be offered at all — the question
 * `GET /api/setup/status` answers for an anonymous visitor as
 * `passkeySignInAvailable`, and the one every passkey endpoint enforces
 * server-side rather than trusting the frontend to hide its own buttons.
 *
 * Available when BOTH hold: the admin toggle (`InstanceSetting::
 * passkeySignInEnabled`) is on, AND the configured relying-party id is valid
 * for the host this request arrived on — the identical registrable-suffix rule
 * RelyingPartyChange enforces on write, shared through RelyingPartyIdRule so
 * the two can never disagree on what counts as a workable configuration.
 *
 * The host comes from ServingHost: judging by PublicBaseUrl instead hid passkey
 * sign-in on every deployment whose public host differs from APP_FRONTEND_URL.
 *
 * Both checks read only instance-wide configuration, so the answer — and its
 * cost — cannot vary with how many accounts exist or how many passkeys are
 * enrolled, the same no-enumeration property AssertionOptionsFactory's own
 * docblock describes for the login-options endpoint.
 */
final readonly class PasskeySignInAvailability
{
    public function __construct(
        private InstanceSettings $settings,
        private PasskeyRelyingParty $relyingParty,
        private RelyingPartyIdRule $relyingPartyIdRule,
        private ServingHost $servingHost,
    ) {
    }

    public function isAvailable(): bool
    {
        if (!$this->settings->passkeySignInEnabled()) {
            return false;
        }

        return $this->relyingPartyIdRule->isValidForHost(
            $this->relyingParty->id(),
            $this->servingHost->get(),
        );
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
