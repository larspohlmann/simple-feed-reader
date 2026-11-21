<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialAccount;
use App\Entity\SocialNotificationPoll;
use App\Enum\SocialAccountStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialNotificationPoll>
 */
final class SocialNotificationPollRepository extends ServiceEntityRepository
{
    private const string DUE_AFTER = '-5 minutes';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialNotificationPoll::class);
    }

    /** @return list<int> ids of connected accounts unchecked for five minutes, never checked first; active owners only */
    public function findAccountIdsDue(\DateTimeImmutable $now, int $limit): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('sa.id')
            ->addSelect('COALESCE(poll.checkedAt, :epoch) AS HIDDEN checkOrder')
            ->from(SocialAccount::class, 'sa')
            ->join('sa.user', 'owner')
            ->leftJoin(SocialNotificationPoll::class, 'poll', 'ON', 'poll.socialAccount = sa')
            ->andWhere('sa.status = :connected')
            ->andWhere('poll.checkedAt IS NULL OR poll.checkedAt < :checkedBefore')
            ->setParameter('connected', SocialAccountStatus::Connected)
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

    public function ensureStamp(SocialAccount $account): SocialNotificationPoll
    {
        $stamp = $this->findOneBy(['socialAccount' => $account]);
        if (null !== $stamp) {
            return $stamp;
        }

        $stamp = new SocialNotificationPoll($account);
        $this->getEntityManager()->persist($stamp);

        return $stamp;
    }

    /**
     * @param list<int> $accountIds
     * @return list<int> ids of those accounts whose token lacks the notifications scope, ascending
     */
    public function scopeMissingAccountIds(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('poll')
            ->select('IDENTITY(poll.socialAccount) AS accountId')
            ->andWhere('poll.socialAccount IN (:accountIds)')
            ->andWhere('poll.scopeMissing = true')
            ->orderBy('accountId', 'ASC')
            ->setParameter('accountIds', $accountIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }
}
