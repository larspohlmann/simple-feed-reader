<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialAccount>
 */
final class SocialAccountRepository extends ServiceEntityRepository
{
    private const array ACTING_ORDER = ['isDefault' => 'DESC', 'connectedAt' => 'ASC', 'id' => 'ASC'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialAccount::class);
    }

    public function findOneForUser(User $user, int $accountId): ?SocialAccount
    {
        return $this->findOneBy(['id' => $accountId, 'user' => $user]);
    }

    /** @return list<SocialAccount> */
    public function findAllForUser(User $user): array
    {
        // @phpstan-ignore arrayValues.list (deliberate: findBy() promises a list only in its @phpstan-return)
        return array_values($this->findBy(['user' => $user], ['network' => 'ASC', 'id' => 'ASC']));
    }

    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    public function findOneByIdentity(User $user, SocialNetwork $network, string $identity): ?SocialAccount
    {
        return $this->findOneBy(['user' => $user, 'network' => $network, 'identity' => $identity]);
    }

    /** @return list<SocialAccount> */
    public function findAllOnNetwork(User $user, SocialNetwork $network): array
    {
        // @phpstan-ignore arrayValues.list (deliberate: findBy() promises a list only in its @phpstan-return)
        return array_values($this->findBy(['user' => $user, 'network' => $network], ['id' => 'ASC']));
    }

    /** @return list<SocialNetwork> each network the user has an account on, once */
    public function networksOf(int $userId): array
    {
        /** @var list<array{network: SocialNetwork}> $rows */
        $rows = $this->createQueryBuilder('sa')
            ->select('DISTINCT sa.network AS network')
            ->andWhere('sa.user = :user')
            ->setParameter('user', $userId)
            ->orderBy('sa.network', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): SocialNetwork => $row['network'], $rows);
    }

    public function findActingOnNetwork(User $user, SocialNetwork $network): ?SocialAccount
    {
        return $this->findOneBy(['user' => $user, 'network' => $network], self::ACTING_ORDER);
    }

    /** The account that acts on $network among those still connected: one that needs a reconnect cannot read. */
    public function findReadyActingOnNetwork(User $user, SocialNetwork $network): ?SocialAccount
    {
        return $this->findOneBy(
            ['user' => $user, 'network' => $network, 'status' => SocialAccountStatus::Connected],
            self::ACTING_ORDER,
        );
    }
}
