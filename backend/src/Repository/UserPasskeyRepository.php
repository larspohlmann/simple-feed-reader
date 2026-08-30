<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserPasskey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserPasskey>
 */
class UserPasskeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserPasskey::class);
    }

    /**
     * The assertion verifier's first question: which credential is this?
     * `credential_id` is unique across every account, so the lookup carries
     * no user.
     */
    public function findOneByCredentialId(string $credentialId): ?UserPasskey
    {
        return $this->findOneBy(['credentialId' => $credentialId]);
    }

    /**
     * Ordered by `createdAt` ascending so the settings list is stable across
     * page loads rather than reflecting whatever order the database happens
     * to return.
     *
     * @return list<UserPasskey>
     */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'ASC']);
    }

    /**
     * The owner-scoped lookup a delete uses: `(id, user)` in one query, so
     * there is no separate "fetch, then compare owner" step for a caller to
     * get wrong or skip.
     */
    public function findOneForUser(User $user, int $id): ?UserPasskey
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    /**
     * Wipes every credential on the instance. Used by the account-reset
     * command, by test teardown, and — since #624 — by
     * {@see \App\Service\Settings\RelyingPartyChange} when an admin changes
     * the WebAuthn relying party id: that is the one request-reachable caller,
     * and it only reaches this method after the request has already been
     * refused once with a 409 naming the credential count and the admin
     * resent it with `invalidateExistingPasskeys` set. No other request path
     * calls this.
     */
    public function deleteAll(): void
    {
        $this->createQueryBuilder('passkey')
            ->delete()
            ->getQuery()
            ->execute();
    }
}
