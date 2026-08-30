<?php

declare(strict_types=1);

namespace App\Service\Settings;

/** Independent of PublicBaseUrl, which is an email-link address, not an origin.
 *  An instance reachable on two hosts derives two ids — set the Passkey domain
 *  explicitly for that case. */
final readonly class ConfiguredPasskeyRelyingParty implements PasskeyRelyingParty
{
    private const string DEFAULT_NAME = 'Simple Feed Reader';

    public function __construct(
        private InstanceSettings $settings,
        private ServingHost $servingHost,
        private EffectivePasskeyRelyingPartyId $effectiveId,
    ) {
    }

    public function id(): string
    {
        return $this->effectiveId->derive($this->settings->getPasskeyRpId(), $this->servingHost->get());
    }

    public function name(): string
    {
        return $this->settings->getPasskeyRpName() ?? self::DEFAULT_NAME;
    }
}
