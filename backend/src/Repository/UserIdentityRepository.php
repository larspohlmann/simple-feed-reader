<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
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
     * providerUserId alone — subject identifiers are unique per provider, not
     * globally, so a single-column lookup would let a collision across
     * providers sign in as the wrong user.
     *
     * Declared explicitly rather than left to EntityRepository's __call,
     * which would read this name as a lookup on a field called
     * `providerAndSubject` and throw. The sharper reason: a name Doctrine
     * could interpret on its own should never be left ambiguous in a
     * security path.
     *
     * The subject is matched exactly, on both engines — a property of the
     * column, not this query: `provider_user_id` is pinned to `utf8mb4_bin`
     * by Version20260721181500, since MySQL would otherwise inherit a
     * case-insensitive default and resolve one provider account to another's
     * local user. App\Entity\UserIdentity explains the choice, including why
     * the sibling `provider` column is deliberately left alone.
     */
    public function findOneByProviderAndSubject(string $provider, string $providerUserId): ?UserIdentity
    {
        return $this->findOneBy([
            'provider' => $provider,
            'providerUserId' => $providerUserId,
        ]);
    }

    /**
     * Whether $user has ANY linked provider — which provider, and how many,
     * is not the question here. Used by PasskeyRemovalPolicy, which only
     * needs to know whether OAuth is a fallback sign-in route at all before
     * it lets the account's last passkey go.
     */
    public function existsForUser(User $user): bool
    {
        return $this->count(['user' => $user]) > 0;
    }

    /**
     * The sign-in providers of every given user, read in ONE query and indexed
     * by user id.
     *
     * User holds no ORM association to UserIdentity — Plan 1 kept that
     * relationship one-directional, letting the FK cascade deletes — so the
     * obvious per-row lookup would be an N+1 no response-body assertion could
     * catch. Pinned by a query count instead: see
     * AdminUserControllerTest::testTheProviderColumnCostsOneQueryHoweverManyUsersAreListed.
     *
     * Only the provider NAME is selected. The row also holds the address the
     * provider last reported, left out deliberately: a second address for the
     * same person, no use in an approval decision, and the hand-built admin
     * row exists precisely to keep columns from reaching an admin's browser
     * merely because they exist.
     *
     * @param list<User> $users
     *
     * @return array<int, list<string>>
     */
    public function providersByUserId(array $users): array
    {
        // An empty IN () is a syntax error on both engines, and there is
        // nothing to ask about anyway — a status filter matching nobody is an
        // ordinary outcome, not an edge case.
        if ([] === $users) {
            return [];
        }

        /** @var list<array{userId: int|string, provider: string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.user) AS userId', 'i.provider')
            ->andWhere('i.user IN (:users)')
            ->setParameter('users', $users)
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $byUser = [];
        foreach ($rows as $row) {
            $byUser[(int) $row['userId']][] = $row['provider'];
        }

        return $byUser;
    }
}
