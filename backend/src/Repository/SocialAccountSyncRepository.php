<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialAccount;
use App\Enum\PrivateFeedSyncSkip;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialAccount> */
final class SocialAccountSyncRepository extends ServiceEntityRepository
{
    private const string DUE_AFTER = '-1 hour';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialAccount::class);
    }

    /** @return list<int> ids unchecked for an hour, never checked first, then the longest unchecked; active owners only */
    public function findIdsDueForSync(\DateTimeImmutable $now, int $limit): array
    {
        $qb = $this->createQueryBuilder('sa')
            ->select('sa.id')
            ->addSelect('COALESCE(sa.syncStamp.checkedAt, :epoch) AS HIDDEN checkOrder')
            ->join('sa.user', 'owner')
            ->andWhere('sa.syncStamp.checkedAt IS NULL OR sa.syncStamp.checkedAt < :checkedBefore')
            ->setParameter('checkedBefore', $now->modify(self::DUE_AFTER))
            ->setParameter('epoch', new \DateTimeImmutable('@0'))
            ->orderBy('checkOrder', 'ASC')
            ->addOrderBy('sa.id', 'ASC')
            ->setMaxResults($limit);
        ActiveAccountDql::restrict($qb, 'owner', $now);

        /** @var list<int|string> $ids */
        $ids = $qb->getQuery()->getSingleColumnResult();

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
