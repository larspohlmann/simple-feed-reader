<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * The one place the WebAuthn registrable-suffix rule is written.
 * Extracted out of RelyingPartyChange, whose write-path guard was the rule's
 * only caller until PasskeySignInAvailability needed the identical check to
 * decide whether passkey sign-in may be offered at all. A second,
 * independently written copy could silently drift from this one — which would
 * let the admin form accept a relying-party id the login page then treats as
 * broken, or the reverse — so both depend on this class instead.
 *
 * The shipped help copy (`settings.instance.passkeyHelp.rule2` and `.rule3`,
 * both locales) promises two things: a public suffix is refused, and so is an
 * IP address, `localhost` excepted. A full public-suffix list is out of scope,
 * so a two-label suffix such as `co.uk` still slips through undetected — but a
 * bare, single-label TLD such as `com` is unambiguous and is refused outright.
 */
final readonly class RelyingPartyIdRule
{
    /**
     * True when $relyingPartyId is a valid WebAuthn relying-party id for
     * $host: the host itself, or a registrable parent domain of it.
     */
    public function isValidForHost(string $relyingPartyId, string $host): bool
    {
        if (!$this->isRegistrableRelyingPartyId($relyingPartyId)) {
            return false;
        }

        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            // An IP literal has no notion of a "subdomain" the way a DNS name
            // does, so a dot-suffix match is meaningless here — only an exact
            // match is. Without this, an id like '1.5' would pass simply
            // because it happens to be a dot-suffix of the host's own IP
            // literal (e.g. '192.168.1.5'), which is exactly the shape
            // `settings.instance.passkeyHelp.rule3` promises is refused.
            return $relyingPartyId === $host;
        }

        return $relyingPartyId === $host || str_ends_with($host, '.' . $relyingPartyId);
    }

    /**
     * An id given as a bare, single-label TLD or as an IP address is refused
     * outright, `localhost` the one named exception — see the class docblock
     * for why a full public-suffix list is out of scope.
     */
    private function isRegistrableRelyingPartyId(string $relyingPartyId): bool
    {
        if ('localhost' === $relyingPartyId) {
            return true;
        }

        if (false !== filter_var($relyingPartyId, \FILTER_VALIDATE_IP)) {
            return false;
        }

        return str_contains($relyingPartyId, '.');
    }
}
