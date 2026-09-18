<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

/**
 * How many feeds each of the given users is subscribed to, in ONE query. A
 * user with no subscriptions is absent from the result (GROUP BY returns no
 * row), so callers default a miss to 0.
 */
final readonly class SubscriptionCountsByUserId
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, int>
     */
    public function forUserIds(array $userIds): array
    {
        // An empty IN () is a syntax error on both engines, and there is
        // nothing to ask about anyway.
        if ([] === $userIds) {
            return [];
        }

        /** @var list<array{userId: int|string, total: int|string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(s.user) AS userId', 'COUNT(s.id) AS total')
            ->from(Subscription::class, 's')
            ->andWhere('s.user IN (:userIds)')->setParameter('userIds', $userIds)
            ->groupBy('s.user')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['userId']] = (int) $row['total'];
        }

        return $counts;
    }
}
