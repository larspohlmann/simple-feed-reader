<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * Resolves the WebAuthn relying party from the admin-editable instance
 * setting, falling back to the public base URL's host — with no scheme, port
 * or subpath — when the admin has set no override (#624). Mirrors
 * ConfiguredPublicBaseUrl's fallback shape, one level up: PublicBaseUrl
 * already resolves the deploy-time default, so this class only has to strip
 * it down to a registrable host.
 *
 * The derivation itself lives in EffectivePasskeyRelyingPartyId, shared with
 * RelyingPartyChange, so "what id is currently in effect" and "what id would
 * be in effect after a given request" can never disagree on the rule.
 */
final readonly class ConfiguredPasskeyRelyingParty implements PasskeyRelyingParty
{
    private const string DEFAULT_NAME = 'Simple Feed Reader';

    public function __construct(
        private InstanceSettings $settings,
        private PublicBaseUrl $publicBaseUrl,
        private EffectivePasskeyRelyingPartyId $effectiveId,
    ) {
    }

    public function id(): string
    {
        return $this->effectiveId->derive($this->settings->getPasskeyRpId(), $this->publicBaseUrl->get());
    }

    public function name(): string
    {
        return $this->settings->getPasskeyRpName() ?? self::DEFAULT_NAME;
    }
}
