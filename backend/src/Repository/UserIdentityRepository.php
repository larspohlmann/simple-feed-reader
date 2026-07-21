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
     * KNOWN GAP, verified against MySQL 8.4 rather than reasoned about: this
     * comparison is only as case-sensitive as the column's collation.
     * `provider_user_id` declares none, so it inherits utf8mb4_0900_ai_ci on
     * MySQL and matches case-insensitively there, while SQLite matches exactly.
     * A subject of `Sub-ABC` is therefore found by a lookup for `sub-abc`.
     *
     * Harmless today and only today: Google's `sub` is decimal digits and
     * Apple's is digits and dots, so neither can produce a pair differing only
     * in case. It stops being harmless the moment a third provider is added —
     * which this design advertises as "one class and one env block" — if that
     * provider issues base64url or hex subjects. Two of its users whose ids
     * differ only in case would then collide: the second would fail to insert
     * against uniq_identity_provider_uid, and, worse, the second to sign in
     * would be handed the first one's account. Closing it means a binary or
     * _bin collation on the column, which is a migration and so deliberately
     * out of scope for a plan that states it needs none.
     */
    public function findOneByProviderAndSubject(string $provider, string $providerUserId): ?UserIdentity
    {
        return $this->findOneBy([
            'provider' => $provider,
            'providerUserId' => $providerUserId,
        ]);
    }
}
