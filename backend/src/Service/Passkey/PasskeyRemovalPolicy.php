<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Service\Passkey\Exception\LastSignInMethodException;
use App\Service\Passkey\PasskeyCount\PasskeyCountInterface;
use App\Service\Passkey\SignInIdentities\SignInIdentitiesInterface;

/**
 * Refuses to remove the last passkey of an account with neither a password hash nor a linked OAuth identity. Which
 * passkey does not matter, only whether another exists; the parameter keeps the call site about this removal.
 */
final readonly class PasskeyRemovalPolicy
{
    public function __construct(
        private PasskeyCountInterface $passkeys,
        private SignInIdentitiesInterface $identities,
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
