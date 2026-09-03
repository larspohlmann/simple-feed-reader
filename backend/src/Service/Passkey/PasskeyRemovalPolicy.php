<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserIdentityRepository;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\Exception\LastSignInMethodException;

/**
 * Refuses the one passkey removal that would lock an account out: deleting
 * the LAST passkey on an account that has neither a password hash nor a
 * linked OAuth identity to sign in with afterwards.
 *
 * `$passkey` is not inspected by the check — removing any one credential
 * always reduces the count by exactly one, so the only question is whether
 * more than one exists BEFORE the delete, not which one. It stays a
 * parameter because the guard is about removing this specific credential,
 * so PasskeyController's call site reads that way, not as "check whether
 * this user may keep signing in" in the abstract.
 */
final readonly class PasskeyRemovalPolicy
{
    public function __construct(
        private UserPasskeyRepository $passkeys,
        private UserIdentityRepository $identities,
    ) {
    }

    /**
     * @throws LastSignInMethodException when $passkey is $user's last one and
     *         no other sign-in method exists
     */
    public function guardRemoval(User $user, UserPasskey $passkey): void
    {
        if ($this->passkeys->countForUser($user) > 1) {
            return;
        }

        if (null !== $user->getPasswordHash() || $this->identities->existsForUser($user)) {
            return;
        }

        throw new LastSignInMethodException();
    }
}
