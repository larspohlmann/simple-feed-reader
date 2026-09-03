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
 * fresh random value for an account's first credential on every call — the
 * value shown to the browser at options time is the one an authenticator
 * remembers and returns at login, so verification must reuse it, not mint a
 * new one.
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
