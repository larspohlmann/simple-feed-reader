<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\Exception\LastSignInMethodException;
use App\Service\Passkey\Exception\PasskeyNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes one of $user's own passkeys (#624; extracted from PasskeyController
 * in #727). The lookup is `(id, user)` in one query, never fetch-by-id then
 * compare owner: a foreign id answers 404, indistinguishable from an id that
 * was never registered, so no 403 can confirm another account's credential.
 */
final readonly class PasskeyRemoval
{
    public function __construct(
        private UserPasskeyRepository $passkeys,
        private PasskeyRemovalPolicy $policy,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PasskeyNotFoundException
     * @throws LastSignInMethodException
     */
    public function remove(User $user, int $id): void
    {
        $passkey = $this->passkeys->findOneForUser($user, $id) ?? throw new PasskeyNotFoundException();

        $this->policy->guardRemoval($user, $passkey);

        $this->entityManager->remove($passkey);
        $this->entityManager->flush();
    }
}
