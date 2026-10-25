<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\Support\UtcDay;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * What the daily history counts: subscriptions, their feeds' arrivals, their owners' opens.
 *
 * @phpstan-type FeedDayCount array{feedId: int, day: string, count: int}
 * @phpstan-type SubscriptionDayCount array{subscriptionId: int, day: string, count: int}
 * @phpstan-type FeedDayRow array{feedId: int|string, day: string, total: int|string}
 * @phpstan-type SubscriptionDayRow array{subscriptionId: int|string, day: string, total: int|string}
 * @phpstan-type TrackedRow array{
 *     subscriptionId: int|string,
 *     feedId: int|string,
 *     createdAt: \DateTimeImmutable,
 *     isSocial: int|string,
 * }
 */
final readonly class SubscriptionActivityRepository
{
    private const int ID_CHUNK_SIZE = 500;

    /** The first ten characters are the UTC day of both MySQL's DATETIME and SQLite's 'Y-m-d H:i:s' text. */
    private const string UTC_DAY_OF = 'SUBSTRING(%s, 1, 10)';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<TrackedSubscriptionModel> */
    public function trackedSubscriptions(): array
    {
        return $this->tracked($this->trackedQuery());
    }

    /** @return list<TrackedSubscriptionModel> */
    public function trackedSubscriptionsOf(int $userId): array
    {
        return $this->tracked(
            $this->trackedQuery()->andWhere('IDENTITY(subscription.user) = :user')->setParameter('user', $userId),
        );
    }

    /**
     * @param list<int> $feedIds
     *
     * @return list<FeedDayCount>
     */
    public function arrivalCountsSince(array $feedIds, \DateTimeImmutable $since): array
    {
        return array_map(
            static fn (array $row): array => [
                'feedId' => (int) $row['feedId'],
                'day' => $row['day'],
                'count' => (int) $row['total'],
            ],
            self::inChunks($feedIds, fn (array $chunk): array => $this->arrivalCountsOf($chunk, $since)),
        );
    }

    /**
     * @param list<int> $subscriptionIds
     *
     * @return list<SubscriptionDayCount>
     */
    public function readCountsSince(array $subscriptionIds, \DateTimeImmutable $since): array
    {
        return array_map(
            static fn (array $row): array => [
                'subscriptionId' => (int) $row['subscriptionId'],
                'day' => $row['day'],
                'count' => (int) $row['total'],
            ],
            self::inChunks($subscriptionIds, fn (array $chunk): array => $this->readCountsOf($chunk, $since)),
        );
    }

    /**
     * @template Row of array<string, mixed>
     *
     * @param list<int>                    $ids
     * @param callable(list<int>): list<Row> $rowsOf
     *
     * @return list<Row>
     */
    private static function inChunks(array $ids, callable $rowsOf): array
    {
        $rows = [];
        foreach (array_chunk($ids, self::ID_CHUNK_SIZE) as $chunk) {
            array_push($rows, ...$rowsOf($chunk));
        }

        return $rows;
    }

    /**
     * @param list<int> $feedIds
     *
     * @return list<FeedDayRow>
     */
    private function arrivalCountsOf(array $feedIds, \DateTimeImmutable $since): array
    {
        /** @var list<FeedDayRow> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(entry.feed) AS feedId', \sprintf(self::UTC_DAY_OF, 'entry.createdAt') . ' AS day')
            ->addSelect('COUNT(entry.id) AS total')
            ->from(Entry::class, 'entry')
            ->andWhere('IDENTITY(entry.feed) IN (:feedIds)')
            ->andWhere('entry.createdAt >= :since')
            ->groupBy('feedId')
            ->addGroupBy('day')
            ->setParameter('feedIds', $feedIds)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /**
     * @param list<int> $subscriptionIds
     *
     * @return list<SubscriptionDayRow>
     */
    private function readCountsOf(array $subscriptionIds, \DateTimeImmutable $since): array
    {
        /** @var list<SubscriptionDayRow> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('subscription.id AS subscriptionId', \sprintf(self::UTC_DAY_OF, 'state.viewedAt') . ' AS day')
            ->addSelect('COUNT(entry.id) AS total')
            ->from(EntryState::class, 'state')
            ->join('state.entry', 'entry')
            ->join(
                Subscription::class,
                'subscription',
                'ON',
                'subscription.feed = entry.feed AND subscription.user = state.user',
            )
            ->andWhere('subscription.id IN (:subscriptionIds)')
            ->andWhere('state.viewedAt >= :since')
            ->groupBy('subscriptionId')
            ->addGroupBy('day')
            ->setParameter('subscriptionIds', $subscriptionIds)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    private function trackedQuery(): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select(
                'subscription.id AS subscriptionId',
                'feed.id AS feedId',
                'subscription.createdAt AS createdAt',
                'CASE WHEN feed.network IS NULL THEN 0 ELSE 1 END AS isSocial',
            )
            ->from(Subscription::class, 'subscription')
            ->join('subscription.feed', 'feed');
        SavedFeedDql::exclude($qb, 'feed');

        return $qb;
    }

    /** @return list<TrackedSubscriptionModel> */
    private function tracked(QueryBuilder $query): array
    {
        /** @var list<TrackedRow> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): TrackedSubscriptionModel => new TrackedSubscriptionModel(
                (int) $row['subscriptionId'],
                (int) $row['feedId'],
                UtcDay::of($row['createdAt']),
                (int) $row['isSocial'] === 1,
            ),
            $rows,
        );
    }
}
