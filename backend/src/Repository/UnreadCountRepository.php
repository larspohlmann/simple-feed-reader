<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Enum\FeedGroup;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Each sidebar scope's unread count; a cross-feed copy counts in every scope holding it. Entries are scanned once,
 * per subscription: a user has one subscription per feed, so every unread entry belongs to exactly one of them, and
 * a scope of subscriptions counts the sum of theirs.
 */
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
        $qb = $this->userSubscriptions($userId)
            ->select('s.id AS subscriptionId', 'COUNT(e.id) AS unreadCount')
            ->join(Entry::class, 'e', 'ON', 'e.feed = s.feed')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = s.user')
            ->groupBy('s.id');
        $this->scope->unread($qb, EntryAliases::primary());

        $counts = [];
        /** @var list<array{subscriptionId: int|string, unreadCount: int|string}> $rows */
        $rows = $qb->getQuery()->getScalarResult();
        foreach ($rows as $row) {
            $counts[(int) $row['subscriptionId']] = (int) $row['unreadCount'];
        }

        return $counts;
    }

    /**
     * @param array<int, int> $perSubscription
     * @return array<int, int> tag id => unread entries across the tag and every tag below it; a tag with
     *                         none is absent
     */
    public function perTag(int $userId, array $perSubscription): array
    {
        return self::sumsPerScope($perSubscription, $this->subscriptionIdsPerTagScope($userId));
    }

    /**
     * @param array<int, int> $perSubscription
     * @return array<string, int> network => unread entries; a network with none is absent
     */
    public function perNetwork(int $userId, array $perSubscription): array
    {
        $scopes = $this->subscriptionIdsPerNetwork($this->subscriptionsOnAnyNetwork($userId));

        return self::sumsPerScope($perSubscription, $scopes);
    }

    /**
     * @param array<int, int> $perSubscription
     * @return array<string, int> network => unread entries of its profile feeds; a network with none is absent
     */
    public function perNetworkProfiles(int $userId, array $perSubscription): array
    {
        $qb = $this->subscriptionsOnAnyNetwork($userId);
        NetworkScopeDql::restrictToProfiles($qb, 'f');

        return self::sumsPerScope($perSubscription, $this->subscriptionIdsPerNetwork($qb));
    }

    /**
     * @param array<int, int> $perSubscription
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

    /** @param array<int, int> $perSubscription */
    public function allItems(int $userId, array $perSubscription): int
    {
        $qb = $this->userSubscriptions($userId)->select('s.id');
        $this->scope->inAllItems($qb, EntryAliases::primary());
        /** @var list<int|string> $ids */
        $ids = $qb->getQuery()->getSingleColumnResult();

        return self::sumOver($perSubscription, array_map(intval(...), $ids));
    }

    /** @return array<int, list<int>> tag id => the subscriptions carrying it or a tag below it, each once */
    private function subscriptionIdsPerTagScope(int $userId): array
    {
        $qb = $this->userSubscriptions($userId)
            ->select('DISTINCT scopeTag.id AS scopeKey', 's.id AS subscriptionId')
            ->innerJoin('s.subscriptionTags', 'subscriptionTag')
            ->innerJoin('subscriptionTag.tag', TagLineageDql::alias('lineage', 0));
        TagLineageDql::joinAncestors($qb, 'lineage');
        $qb->innerJoin(
            Tag::class,
            'scopeTag',
            'ON',
            TagLineageDql::reaches('lineage', 'scopeTag.id') . ' AND scopeTag.user = :user',
        );

        $scopes = [];
        foreach (self::scopeRows($qb) as $row) {
            $scopes[(int) $row['scopeKey']][] = (int) $row['subscriptionId'];
        }

        return $scopes;
    }

    /** @return array<string, list<int>> network => its subscriptions */
    private function subscriptionIdsPerNetwork(QueryBuilder $qb): array
    {
        $scopes = [];
        foreach (self::scopeRows($qb) as $row) {
            $scopes[(string) $row['scopeKey']][] = (int) $row['subscriptionId'];
        }

        return $scopes;
    }

    private function subscriptionsOnAnyNetwork(int $userId): QueryBuilder
    {
        return $this->userSubscriptions($userId)
            ->select('f.network AS scopeKey', 's.id AS subscriptionId')
            ->join('s.feed', 'f')
            ->andWhere('f.network IS NOT NULL');
    }

    private function userSubscriptions(int $userId): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Subscription::class, 's')
            ->andWhere('s.user = :user')
            ->setParameter('user', $userId);
    }

    /** @return list<array{scopeKey: int|string, subscriptionId: int|string}> */
    private static function scopeRows(QueryBuilder $qb): array
    {
        /** @var list<array{scopeKey: int|string, subscriptionId: int|string}> $rows */
        $rows = $qb->getQuery()->getScalarResult();

        return $rows;
    }

    /**
     * @template TScope of int|string
     * @param array<int, int> $perSubscription
     * @param array<TScope, list<int>> $scopes scope => its subscription ids
     * @return array<TScope, int> a scope with no unread entries is absent
     */
    private static function sumsPerScope(array $perSubscription, array $scopes): array
    {
        return array_filter(array_map(
            static fn (array $subscriptionIds): int => self::sumOver($perSubscription, $subscriptionIds),
            $scopes,
        ));
    }

    /**
     * @param array<int, int> $perSubscription
     * @param list<int> $subscriptionIds
     */
    private static function sumOver(array $perSubscription, array $subscriptionIds): int
    {
        return array_sum(array_intersect_key($perSubscription, array_flip($subscriptionIds)));
    }
}
