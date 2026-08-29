<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * The one place the "stored override, else derive from the public base URL's
 * host" rule is written (#624). ConfiguredPasskeyRelyingParty::id() and
 * RelyingPartyChange both need this exact derivation — the first to answer
 * "what id is in effect right now", the second to answer "what id would be in
 * effect after this request" — and a second copy of the `?? host(...)`
 * fallback would be free to drift from the first. A drift here is invisible
 * until it lets a relying-party id change slip past the guard and silently
 * orphan every enrolled passkey, so this is deliberately its own class rather
 * than a private method duplicated in both callers.
 */
final readonly class EffectivePasskeyRelyingPartyId
{
    public function derive(?string $configuredRelyingPartyId, string $publicBaseUrl): string
    {
        if (null !== $configuredRelyingPartyId) {
            return $configuredRelyingPartyId;
        }

        $host = parse_url($publicBaseUrl, PHP_URL_HOST);

        return \is_string($host) ? $host : '';
    }
}
