<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * Whether an id could work at all, never whether it matches this host: the browser enforces the origin match, and a
 * server that guessed would refuse correct configurations behind a proxy.
 */
final readonly class RelyingPartyIdRule
{
    public function isUsable(string $relyingPartyId): bool
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
