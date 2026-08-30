<?php

declare(strict_types=1);

namespace App\Service\Passkey\Exception;

use App\Exception\ApiException;

/**
 * The handle redeemed from PasskeyChallengeStore names a different account
 * than the one completing the ceremony. This is what binds a registration
 * challenge to its owner: without it, one account's authenticated session
 * could complete a ceremony started under another account's registration
 * options, enrolling a credential nobody there would recognise.
 */
final class PasskeyChallengeOwnershipException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'passkey_challenge_owner_mismatch',
            403,
            'Forbidden',
            'This registration challenge was not issued to you.',
        );
    }
}
