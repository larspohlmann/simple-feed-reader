<?php

declare(strict_types=1);

namespace App\Service\Settings;

/** Shared so "the id in effect now" and "the id after this request" cannot
 *  drift: a drift there orphans every enrolled passkey. */
final readonly class EffectivePasskeyRelyingPartyId
{
    public function derive(?string $configuredRelyingPartyId, string $servingHost): string
    {
        return $configuredRelyingPartyId ?? $servingHost;
    }
}
