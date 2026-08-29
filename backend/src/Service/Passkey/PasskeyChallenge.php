<?php

declare(strict_types=1);

namespace App\Service\Passkey;

/**
 * A challenge redeemed from PasskeyChallengeStore, together with the account
 * it was issued for and, for a registration ceremony, the user handle that
 * was minted for it. $userId and $userHandle are both null for a
 * discoverable-credential (usernameless) login ceremony, where no account is
 * known until the assertion comes back.
 *
 * $userHandle exists on this record, rather than being re-derived at
 * verification time, because PasskeyCredentials::userHandleFor() mints a
 * fresh random value for an account's first credential on every call: minted
 * once at options time and again at verification time, in two separate HTTP
 * requests, those two calls return DIFFERENT bytes. The value shown to the
 * browser at options time is the one a real authenticator remembers and
 * later returns at login, so verification MUST reuse that exact value
 * rather than asking PasskeyCredentials for a new one (#624, fix round 1).
 */
final readonly class PasskeyChallenge
{
    public function __construct(
        public string $challenge,
        public ?int $userId,
        public ?string $userHandle,
    ) {
    }
}
