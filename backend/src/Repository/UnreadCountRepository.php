<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Enum\FeedGroup;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** Each sidebar scope's unread count, by the list's own scope rules; a cross-feed copy counts in every scope holding it. */
final readonly class UnreadCountRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryScopePredicates $scope,
        private SubscriptionScopeRepository $subscriptionScopes,
    ) {
    }

    /** @return array<int, int> subscription id => unread entries; a subscription with none is absent */
    public function perSubscription(int $userId): array
    {
        return $this->countsByIntegerKey(
            $this->countedBy($this->unreadEntries($userId), EntryAliases::primary()->subscriptionKey()),
        );
    }

    /**
     * @param array<int, int> $perSubscription the user's {@see perSubscription()}
     * @return array<int, int> tag id => unread entries across the tag and every tag below it; a tag with
     *                         none is absent
     */
    public function perTag(int $userId, array $perSubscription): array
    {
        $counts = [];
        foreach ($this->subscriptionScopes->idsPerTagScopeForUser($userId) as $tagId => $subscriptionIds) {
            $count = self::sumOver($perSubscription, $subscriptionIds);
            if ($count > 0) {
                $counts[$tagId] = $count;
            }
        }

        return $counts;
    }

    /** @return array<string, int> network => unread entries; a network with none is absent */
    public function perNetwork(int $userId): array
    {
        $aliases = EntryAliases::primary();

        return $this->countsByNetwork(
            $this->countedBy($this->networkEntries($userId, $aliases), $aliases->networkKey()),
        );
    }

    /** @return array<string, int> network => unread entries of its profile feeds; a network with none is absent */
    public function perNetworkProfiles(int $userId): array
    {
        $aliases = EntryAliases::primary();
        $qb = $this->networkEntries($userId, $aliases);
        NetworkScopeDql::restrictToProfiles($qb, $aliases->networkFeed);

        return $this->countsByNetwork($this->countedBy($qb, $aliases->networkKey()));
    }

    /**
     * @param array<int, int> $perSubscription the user's {@see perSubscription()}
     * @return array{tags: int, feeds: int}
     */
    public function perFeedGroup(int $userId, array $perSubscription): array
    {
        return [
            'tags' => self::sumOver(
                $perSubscription,
                $this->subscriptionScopes->idsForUserInFeedGroup($userId, FeedGroup::Tags),
            ),
            'feeds' => self::sumOver(
                $perSubscription,
                $this->subscriptionScopes->idsForUserInFeedGroup($userId, FeedGroup::Feeds),
            ),
        ];
    }

    /** @param array<int, int> $perSubscription the user's {@see perSubscription()} */
    public function allItems(int $userId, array $perSubscription): int
    {
        return self::sumOver($perSubscription, $this->subscriptionScopes->idsIncludedInAllItemsForUser($userId));
    }

    /**
     * A subscription's entries belong to it alone (one subscription per user and feed), so a scope of
     * subscriptions counts the sum of theirs.
     *
     * @param array<int, int> $perSubscription
     * @param list<int> $subscriptionIds
     */
    private static function sumOver(array $perSubscription, array $subscriptionIds): int
    {
        return array_sum(array_intersect_key($perSubscription, array_flip($subscriptionIds)));
    }

    private function networkEntries(int $userId, EntryAliases $aliases): QueryBuilder
    {
        $qb = $this->unreadEntries($userId);
        $this->scope->joinNetworkFeeds($qb, $aliases);

        return $qb;
    }

    private function unreadEntries(int $userId): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(Subscription::class, 's')
            ->join(Entry::class, 'e', 'ON', 'e.feed = s.feed')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = s.user')
            ->andWhere('s.user = :user')
            ->setParameter('user', $userId);
        $this->scope->unread($qb, EntryAliases::primary());

        return $qb;
    }

    private function countedBy(QueryBuilder $qb, string $scopeKey): QueryBuilder
    {
        return $qb->select($scopeKey . ' AS scopeKey', 'COUNT(e.id) AS unreadCount')->groupBy('scopeKey');
    }

    /** @return array<int, int> */
    private function countsByIntegerKey(QueryBuilder $qb): array
    {
        $counts = [];
        /** @var list<array{scopeKey: int|string, unreadCount: int|string}> $rows */
        $rows = $qb->getQuery()->getScalarResult();
        foreach ($rows as $row) {
            $counts[(int) $row['scopeKey']] = (int) $row['unreadCount'];
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function countsByNetwork(QueryBuilder $qb): array
    {
        $counts = [];
        /** @var list<array{scopeKey: string, unreadCount: int|string}> $rows */
        $rows = $qb->getQuery()->getScalarResult();
        foreach ($rows as $row) {
            $counts[$row['scopeKey']] = (int) $row['unreadCount'];
        }

        return $counts;
    }
}
