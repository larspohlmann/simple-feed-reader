<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialNotification>
 */
final class SocialNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialNotification::class);
    }

    /**
     * @param list<string> $remoteIds
     * @return array<string, SocialNotification>
     */
    public function findByRemoteIds(SocialAccount $account, array $remoteIds): array
    {
        if ($remoteIds === []) {
            return [];
        }

        /** @var list<SocialNotification> $rows */
        $rows = $this->createQueryBuilder('notification')
            ->andWhere('notification.socialAccount = :account')
            ->andWhere('notification.remoteId IN (:remoteIds)')
            ->setParameter('account', $account)
            ->setParameter('remoteIds', $remoteIds)
            ->getQuery()
            ->getResult();

        $byRemoteId = [];
        foreach ($rows as $row) {
            $byRemoteId[$row->getRemoteId()] = $row;
        }

        return $byRemoteId;
    }

    /** A bulk UPDATE: it bypasses the identity map, so a caller holding these rows must clear() or refresh them. */
    public function markSeenUpTo(SocialAccount $account, \DateTimeImmutable $until): void
    {
        $this->createQueryBuilder('notification')
            ->update()
            ->set('notification.seen', 'true')
            ->andWhere('notification.socialAccount = :account')
            ->andWhere('notification.createdAt <= :until')
            ->andWhere('notification.seen = false')
            ->setParameter('account', $account)
            ->setParameter('until', $until, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }

    public function deleteCreatedBefore(\DateTimeImmutable $cutoff): int
    {
        /** @var int $deleted */
        $deleted = $this->createQueryBuilder('notification')
            ->delete()
            ->andWhere('notification.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();

        return $deleted;
    }
}
