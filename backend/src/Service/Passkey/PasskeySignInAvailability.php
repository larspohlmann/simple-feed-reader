<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Service\Passkey\Exception\PasskeySignInDisabledException;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\PasskeyRelyingParty;
use App\Service\Settings\PublicBaseUrl;
use App\Service\Settings\RelyingPartyIdRule;

/**
 * Whether passkey sign-in may be offered at all (#624 follow-up) — the
 * question `GET /api/setup/status` answers for an anonymous visitor as
 * `passkeySignInAvailable`, and the one every passkey endpoint enforces
 * server-side rather than trusting the frontend to hide its own buttons.
 *
 * Available when BOTH hold: the admin toggle (`InstanceSetting::
 * passkeySignInEnabled`) is on, AND the configured relying-party id is valid
 * for the public base URL's host — the identical registrable-suffix rule
 * RelyingPartyChange enforces on write, shared through RelyingPartyIdRule so
 * the two can never disagree on what counts as a workable configuration.
 *
 * NEVER derived from a credential or account count. Both checks read only
 * instance-wide configuration, so the answer — and its cost — cannot vary
 * with how many accounts exist or how many passkeys are enrolled. That is
 * the same no-enumeration property AssertionOptionsFactory's own docblock
 * calls out for the login-options endpoint; this class must not become the
 * back door that reintroduces it.
 */
final readonly class PasskeySignInAvailability
{
    public function __construct(
        private InstanceSettings $settings,
        private PublicBaseUrl $publicBaseUrl,
        private PasskeyRelyingParty $relyingParty,
        private RelyingPartyIdRule $relyingPartyIdRule,
    ) {
    }

    public function isAvailable(): bool
    {
        if (!$this->settings->passkeySignInEnabled()) {
            return false;
        }

        $host = parse_url($this->publicBaseUrl->get(), PHP_URL_HOST);

        return \is_string($host) && $this->relyingPartyIdRule->isValidForHost($this->relyingParty->id(), $host);
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
