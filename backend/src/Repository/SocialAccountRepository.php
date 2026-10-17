<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\PrivateFeedSyncSkip;
use App\Enum\SocialNetwork;
use App\Enum\UserStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialAccount>
 */
final class SocialAccountRepository extends ServiceEntityRepository
{
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
        return $this->findOneBy(
            ['user' => $user, 'network' => $network],
            ['isDefault' => 'DESC', 'connectedAt' => 'ASC', 'id' => 'ASC'],
        );
    }

    /** @return list<int> ids, never checked first, then the longest unchecked; accounts of active users only */
    public function findIdsDueForSync(\DateTimeImmutable $checkedBefore, int $limit): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('sa')
            ->select('sa.id')
            ->addSelect('COALESCE(sa.syncStamp.checkedAt, :epoch) AS HIDDEN checkOrder')
            ->join('sa.user', 'owner')
            ->andWhere('owner.status = :active')
            ->andWhere('sa.syncStamp.checkedAt IS NULL OR sa.syncStamp.checkedAt < :checkedBefore')
            ->setParameter('active', UserStatus::Active)
            ->setParameter('checkedBefore', $checkedBefore)
            ->setParameter('epoch', new \DateTimeImmutable('@0'))
            ->orderBy('checkOrder', 'ASC')
            ->addOrderBy('sa.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }

    public function recordFailedSync(int $accountId, \DateTimeImmutable $checkedAt): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE social_account SET last_checked_at = :checkedAt, last_sync_skip = :failed WHERE id = :id',
            ['checkedAt' => $checkedAt, 'failed' => PrivateFeedSyncSkip::Failed->value, 'id' => $accountId],
            ['checkedAt' => Types::DATETIME_IMMUTABLE],
        );
    }
}
