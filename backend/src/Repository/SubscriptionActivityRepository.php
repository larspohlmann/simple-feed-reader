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

/** What the daily history counts: subscriptions, their feeds' arrivals, their owners' opens. */
final readonly class SubscriptionActivityRepository
{
    private const int ID_CHUNK_SIZE = 500;

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
     * @param list<int> $subscriptionIds
     *
     * @return list<array{subscriptionId: int, happenedAt: \DateTimeImmutable}>
     */
    public function arrivalsSince(array $subscriptionIds, \DateTimeImmutable $since): array
    {
        return $this->inChunks($subscriptionIds, fn (array $chunk): QueryBuilder => $this->entityManager
            ->createQueryBuilder()
            ->select('subscription.id AS subscriptionId', 'entry.createdAt AS happenedAt')
            ->from(Entry::class, 'entry')
            ->join(Subscription::class, 'subscription', 'WITH', 'subscription.feed = entry.feed')
            ->andWhere('subscription.id IN (:subscriptionIds)')
            ->andWhere('entry.createdAt >= :since')
            ->setParameter('subscriptionIds', $chunk)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE));
    }

    /**
     * @param list<int> $subscriptionIds
     *
     * @return list<array{subscriptionId: int, happenedAt: \DateTimeImmutable}>
     */
    public function viewsSince(array $subscriptionIds, \DateTimeImmutable $since): array
    {
        return $this->inChunks($subscriptionIds, fn (array $chunk): QueryBuilder => $this->entityManager
            ->createQueryBuilder()
            ->select('subscription.id AS subscriptionId', 'state.viewedAt AS happenedAt')
            ->from(EntryState::class, 'state')
            ->join('state.entry', 'entry')
            ->join(
                Subscription::class,
                'subscription',
                'WITH',
                'subscription.feed = entry.feed AND subscription.user = state.user',
            )
            ->andWhere('subscription.id IN (:subscriptionIds)')
            ->andWhere('state.viewedAt >= :since')
            ->setParameter('subscriptionIds', $chunk)
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE));
    }

    private function trackedQuery(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select(
                'subscription.id AS subscriptionId',
                'subscription.createdAt AS createdAt',
                'CASE WHEN feed.network IS NULL THEN 0 ELSE 1 END AS isSocial',
            )
            ->from(Subscription::class, 'subscription')
            ->join('subscription.feed', 'feed');
    }

    /** @return list<TrackedSubscriptionModel> */
    private function tracked(QueryBuilder $query): array
    {
        /** @var list<array{subscriptionId: int|string, createdAt: \DateTimeImmutable, isSocial: int|string}> $rows */
        $rows = $query->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row): TrackedSubscriptionModel => new TrackedSubscriptionModel(
                (int) $row['subscriptionId'],
                UtcDay::of($row['createdAt']),
                (int) $row['isSocial'] === 1,
            ),
            $rows,
        );
    }

    /**
     * @param list<int>                         $subscriptionIds
     * @param callable(list<int>): QueryBuilder $queryFor
     *
     * @return list<array{subscriptionId: int, happenedAt: \DateTimeImmutable}>
     */
    private function inChunks(array $subscriptionIds, callable $queryFor): array
    {
        $instants = [];
        foreach (array_chunk($subscriptionIds, self::ID_CHUNK_SIZE) as $chunk) {
            /** @var list<array{subscriptionId: int|string, happenedAt: \DateTimeImmutable}> $rows */
            $rows = $queryFor($chunk)->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $instants[] = ['subscriptionId' => (int) $row['subscriptionId'], 'happenedAt' => $row['happenedAt']];
            }
        }

        return $instants;
    }
}
