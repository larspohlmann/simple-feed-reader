<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubscriptionEntryCountRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<int, int> subscription id => entries in its feed, read or not
     */
    public function forUser(int $userId): array
    {
        /** @var list<array{subscriptionId: int, entryCount: int}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.id AS subscriptionId', 'COUNT(e.id) AS entryCount')
            ->from(Subscription::class, 's')
            ->join(Entry::class, 'e', 'ON', 'e.feed = s.feed')
            ->andWhere('s.user = :user')
            ->groupBy('s.id')
            ->setParameter('user', $userId)
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['subscriptionId']] = (int) $row['entryCount'];
        }

        return $counts;
    }
}
