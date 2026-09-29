<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserIdentity;
use App\Service\Passkey\SignInIdentities\SignInIdentitiesInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserIdentity>
 */
final class UserIdentityRepository extends ServiceEntityRepository implements SignInIdentitiesInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserIdentity::class);
    }

    /** Both columns, never the subject alone: a subject is unique per provider only, so one could match another's. */
    public function findOneByProviderAndSubject(string $provider, string $providerUserId): ?UserIdentity
    {
        return $this->findOneBy([
            'provider' => $provider,
            'providerUserId' => $providerUserId,
        ]);
    }

    public function existsForUser(User $user): bool
    {
        return $this->count(['user' => $user]) > 0;
    }

    /**
     * One query for every user's provider names, since User has no association to walk (pinned by a query count in
     * AdminUserControllerTest). The provider-reported address is left out on purpose: it has no place in an approval.
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
