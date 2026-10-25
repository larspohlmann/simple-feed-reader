<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SubscriptionDay;
use App\Service\SubscriptionHistory\Model\DayCountModel;
use App\Service\SubscriptionHistory\Model\DayCountsModel;
use App\Service\SubscriptionHistory\Support\UtcDay;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriptionDay>
 */
final class SubscriptionDayRepository extends ServiceEntityRepository
{
    private const int ID_CHUNK_SIZE = 500;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionDay::class);
    }

    /**
     * @param list<int> $subscriptionIds
     */
    public function since(array $subscriptionIds, string $oldestDay): DayCountsModel
    {
        $bySubscription = [];
        foreach (array_chunk($subscriptionIds, self::ID_CHUNK_SIZE) as $chunk) {
            foreach ($this->rowsSince($chunk, $oldestDay) as $row) {
                $bySubscription[(int) $row['subscriptionId']][UtcDay::of($row['day'])] =
                    new DayCountModel((int) $row['arrived'], (int) $row['readCount']);
            }
        }

        return new DayCountsModel($bySubscription);
    }

    /**
     * @param list<int> $subscriptionIds
     *
     * @return list<array{subscriptionId: int|string, day: \DateTimeImmutable, arrived: int|string, readCount: int|string}>
     */
    private function rowsSince(array $subscriptionIds, string $oldestDay): array
    {
        /** @var list<array{subscriptionId: int|string, day: \DateTimeImmutable, arrived: int|string, readCount: int|string}> $rows */
        $rows = $this->createQueryBuilder('history')
            ->select(
                'IDENTITY(history.subscription) AS subscriptionId',
                'history.day AS day',
                'history.arrived AS arrived',
                'history.readCount AS readCount',
            )
            ->andWhere('IDENTITY(history.subscription) IN (:subscriptionIds)')
            ->andWhere('history.day >= :oldestDay')
            ->setParameter('subscriptionIds', $subscriptionIds)
            ->setParameter('oldestDay', UtcDay::start($oldestDay), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }
}
