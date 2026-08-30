<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Service\Passkey\Exception\PasskeySignInDisabledException;
use App\Service\Settings\EffectivePasskeyRelyingPartyId;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\PasskeyRelyingParty;
use App\Service\Settings\PublicBaseUrl;
use App\Service\Settings\RelyingPartyIdRule;

/**
 * Whether passkey sign-in may be offered at all — the question
 * `GET /api/setup/status` answers for an anonymous visitor as
 * `passkeySignInAvailable`, and the one every passkey endpoint enforces
 * server-side rather than trusting the frontend to hide its own buttons.
 *
 * Available when BOTH hold: the admin toggle (`InstanceSetting::
 * passkeySignInEnabled`) is on, AND the configured relying-party id is valid
 * for the public base URL's host — the identical registrable-suffix rule
 * RelyingPartyChange enforces on write, shared through RelyingPartyIdRule so
 * the two can never disagree on what counts as a workable configuration.
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
        private PublicBaseUrl $publicBaseUrl,
        private PasskeyRelyingParty $relyingParty,
        private RelyingPartyIdRule $relyingPartyIdRule,
        private EffectivePasskeyRelyingPartyId $effectiveId,
    ) {
    }

    public function isAvailable(): bool
    {
        if (!$this->settings->passkeySignInEnabled()) {
            return false;
        }

        return $this->relyingPartyIdRule->isValidForHost($this->relyingParty->id(), $this->host());
    }

    /**
     * The public base URL's host, without a scheme or path — the same
     * `parse_url(..., PHP_URL_HOST)` fallback RelyingPartyChange and
     * ConfiguredPasskeyRelyingParty both already need, so it lives in
     * EffectivePasskeyRelyingPartyId rather than a copy here. Passing `null`
     * as the "configured id" forces derive() into its host-fallback branch —
     * the exact thing this method wants — and an unparseable URL degrades to
     * `''`, which RelyingPartyIdRule::isValidForHost() already refuses for
     * any real relying-party id.
     */
    private function host(): string
    {
        return $this->effectiveId->derive(null, $this->publicBaseUrl->get());
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
