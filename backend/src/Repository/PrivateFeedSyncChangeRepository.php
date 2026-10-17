<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PrivateFeedSyncChange;
use App\Entity\SocialAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrivateFeedSyncChange>
 */
final class PrivateFeedSyncChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrivateFeedSyncChange::class);
    }

    public function trimTo(SocialAccount $account, int $keep): void
    {
        $surplusIds = $this->createQueryBuilder('change')
            ->select('change.id')
            ->andWhere('change.socialAccount = :account')
            ->setParameter('account', $account)
            ->orderBy('change.changedAt', 'DESC')
            ->addOrderBy('change.id', 'DESC')
            ->setFirstResult($keep)
            ->getQuery()
            ->getSingleColumnResult();
        if ([] === $surplusIds) {
            return;
        }

        $this->createQueryBuilder('change')
            ->delete()
            ->andWhere('change.id IN (:ids)')
            ->setParameter('ids', $surplusIds)
            ->getQuery()
            ->execute();
    }

    /**
     * @param list<SocialAccount> $accounts
     *
     * @return array<int, list<PrivateFeedSyncChange>> newest first, by account id
     */
    public function recentOf(array $accounts): array
    {
        if ([] === $accounts) {
            return [];
        }
        /** @var list<PrivateFeedSyncChange> $changes */
        $changes = $this->createQueryBuilder('change')
            ->andWhere('change.socialAccount IN (:accounts)')
            ->setParameter('accounts', $accounts)
            ->orderBy('change.changedAt', 'DESC')
            ->addOrderBy('change.id', 'DESC')
            ->getQuery()
            ->getResult();

        $byAccountId = [];
        foreach ($changes as $change) {
            $byAccountId[$change->getSocialAccount()->requireId()][] = $change;
        }

        return $byAccountId;
    }
}
