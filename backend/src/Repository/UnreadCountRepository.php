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

    /** @return array<int, int> tag id => unread entries, a parent's spanning its children's feeds; a tag with none is absent */
    public function perTag(int $userId): array
    {
        return $this->perChildTag($userId) + $this->perRootTag($userId);
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

    /** @return array{tags: int, feeds: int} */
    public function perFeedGroup(int $userId): array
    {
        return [
            'tags' => $this->unreadInFeedGroup($userId, FeedGroup::Tags),
            'feeds' => $this->unreadInFeedGroup($userId, FeedGroup::Feeds),
        ];
    }

    public function allItems(int $userId): int
    {
        $qb = $this->unreadEntries($userId)->select('COUNT(e.id)');
        $this->scope->inAllItems($qb, EntryAliases::primary());

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function unreadInFeedGroup(int $userId, FeedGroup $group): int
    {
        $subscriptionIds = $this->subscriptionScopes->idsForUserInFeedGroup($userId, $group);
        if ($subscriptionIds === []) {
            return 0;
        }

        $qb = $this->unreadEntries($userId)->select('COUNT(e.id)');
        $this->scope->inSubscriptions($qb, EntryAliases::primary(), $subscriptionIds);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return array<int, int> */
    private function perChildTag(int $userId): array
    {
        $qb = $this->taggedUnreadEntries($userId)->andWhere('t.parent IS NOT NULL');

        return $this->countsByIntegerKey($this->countedBy($qb, EntryAliases::primary()->tagKey()));
    }

    /** @return array<int, int> */
    private function perRootTag(int $userId): array
    {
        $qb = $this->taggedUnreadEntries($userId)->leftJoin('t.parent', 'p');

        return $this->countsByIntegerKey($this->countedDistinctBy($qb, 'COALESCE(p.id, t.id)'));
    }

    private function taggedUnreadEntries(int $userId): QueryBuilder
    {
        $aliases = EntryAliases::primary();
        $qb = $this->unreadEntries($userId);
        $this->scope->joinTags($qb, $aliases);

        return $qb->innerJoin($aliases->tag . '.tag', 't');
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

    /** A feed carrying a parent and its child yields two rows per entry; DISTINCT folds them. */
    private function countedDistinctBy(QueryBuilder $qb, string $scopeKey): QueryBuilder
    {
        return $qb->select($scopeKey . ' AS scopeKey', 'COUNT(DISTINCT e.id) AS unreadCount')->groupBy('scopeKey');
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
