<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;

/**
 * How many feeds each of the given users is subscribed to, in ONE query.
 *
 * Split out of SubscriptionRepository rather than added there: that class
 * already carries ten other query shapes, and this one query has exactly one
 * caller (the admin user list, paired with TagRepository::countsByUserIds()
 * for the same list) — a standalone collaborator names that narrow purpose
 * plainly, where one more repository method would not.
 *
 * A user with no subscriptions is absent from the result rather than present
 * with a zero — GROUP BY has no row to return for them — so callers must
 * default a miss to 0. The obvious per-user countForUser() loop would be an
 * N+1 that no assertion on the response body could catch, which is why
 * AdminUserControllerTest counts the queries.
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
