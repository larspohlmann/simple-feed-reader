<?php

declare(strict_types=1);

namespace App\Service\Passkey\Model;

/**
 * A redeemed challenge: its account and, for a registration, the user handle minted for it (both null for a
 * discoverable login). Verification must reuse that handle: the authenticator returns it at every later login.
 */
final readonly class PasskeyChallengeModel
{
    public function __construct(
        public string $challenge,
        public ?int $userId,
        public ?string $userHandle,
    ) {
    }
}
