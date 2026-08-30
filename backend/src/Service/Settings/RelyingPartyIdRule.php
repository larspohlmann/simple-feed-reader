<?php

declare(strict_types=1);

namespace App\Service\Settings;

/**
 * Whether a relying-party id could work at all. It is NOT checked against the
 * host the server thinks it is on: only the browser knows the origin it is
 * really at, and it enforces the match itself with a SecurityError. A server
 * that guessed would refuse correct configurations behind a proxy.
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
