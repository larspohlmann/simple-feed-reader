<?php

declare(strict_types=1);

namespace App\Service\Passkey;

/**
 * A challenge redeemed from PasskeyChallengeStore, together with the account
 * it was issued for. $userId is null for a discoverable-credential (usernameless)
 * login ceremony, where no account is known until the assertion comes back.
 */
final readonly class PasskeyChallenge
{
    public function __construct(
        public string $challenge,
        public ?int $userId,
    ) {
    }
}
