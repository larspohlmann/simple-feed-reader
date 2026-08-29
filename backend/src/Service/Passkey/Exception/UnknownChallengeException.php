<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * The handle presented to PasskeyChallengeStore::consume() is not redeemable:
 * never issued, already redeemed, or past its five-minute lifetime.
 * Collapsed into a single case on purpose — a caller who could tell "expired"
 * from "already used" from "never existed" could use the distinction to probe
 * for live handles, the same reasoning OAuthStateStore applies to `state`.
 */
final class UnknownChallengeException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'unknown_passkey_challenge',
            400,
            'Unknown or expired passkey challenge',
        );
    }
}
