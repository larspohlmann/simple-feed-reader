<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubscribedEntryCountRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function countFor(int $userId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(Entry::class, 'e')
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
