<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserIdentity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserIdentity>
 */
class UserIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserIdentity::class);
    }

    /**
     * The OAuth callback's first question: have we seen this person before?
     *
     * Queried on both columns of uniq_identity_provider_uid, never on
     * providerUserId alone. Subject identifiers are unique per provider, not
     * globally, so a single-column lookup would let a collision across
     * providers sign in as the wrong user.
     *
     * Declared explicitly rather than left to EntityRepository's __call. That
     * magic would read this exact name as a lookup on one field called
     * `providerAndSubject` and throw, so the method has to exist — but the
     * sharper reason to write it out is that a name Doctrine is willing to
     * interpret on its own should never be left ambiguous in a security path.
     *
     * The subject is matched exactly, on both engines. That is a property of
     * the column rather than of this query: `provider_user_id` is pinned to
     * `utf8mb4_bin` by Version20260721181500, because MySQL would otherwise
     * inherit a case-insensitive table default and resolve one provider account
     * to another's local user. App\Entity\UserIdentity explains the choice at
     * the column, including why the sibling `provider` column is deliberately
     * left alone.
     */
    public function findOneByProviderAndSubject(string $provider, string $providerUserId): ?UserIdentity
    {
        return $this->findOneBy([
            'provider' => $provider,
            'providerUserId' => $providerUserId,
        ]);
    }
}
