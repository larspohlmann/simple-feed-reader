<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Enum\SourceFormat;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** Each sidebar scope's unread count, collapsing cross-feed copies within that scope only, as its list does. */
final readonly class UnreadCountRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DuplicateCollapseDql $collapse,
    ) {
    }

    /** @return array<int, int> subscription id => unread entries; a subscription with none is absent */
    public function perSubscription(int $userId): array
    {
        $qb = $this->unreadEntries($userId)
            ->select('s.id AS scopeKey', 'COUNT(e.id) AS unreadCount')
            ->groupBy('s.id');
        $this->collapseWithin($qb, $userId, static function (QueryBuilder $inner): void {
            $inner->andWhere('s2.id = s.id');
        });

        return $this->countsByIntegerKey($qb);
    }

    /** @return array<int, int> tag id => unread entries; a tag with none is absent */
    public function perTag(int $userId): array
    {
        $qb = $this->unreadEntries($userId)
            ->innerJoin('s.subscriptionTags', 'st')
            ->select('IDENTITY(st.tag) AS scopeKey', 'COUNT(e.id) AS unreadCount')
            ->groupBy('st.tag');
        $this->collapseWithin($qb, $userId, static function (QueryBuilder $inner): void {
            $inner->innerJoin('s2.subscriptionTags', 'st2', 'WITH', 'IDENTITY(st2.tag) = IDENTITY(st.tag)');
        });

        return $this->countsByIntegerKey($qb);
    }

    /** @return array<string, int> network => unread entries; a network with none is absent */
    public function perNetwork(int $userId): array
    {
        return $this->countsByNetwork($this->networkCounts($userId, self::everyFeed()));
    }

    /** @return array<string, int> network => unread entries of its profile feeds; a network with none is absent */
    public function perNetworkProfiles(int $userId): array
    {
        return $this->countsByNetwork($this->networkCounts(
            $userId,
            static function (QueryBuilder $qb, string $feedAlias): void {
                PublicOrAuthorFeedDql::restrict($qb, $feedAlias, SourceFormat::NOT_PROFILES);
            },
        ));
    }

    public function allItems(int $userId): int
    {
        $qb = $this->unreadEntries($userId)
            ->select('COUNT(e.id)')
            ->andWhere('s.includeInAllItems = true');
        $this->collapseWithin($qb, $userId, static function (QueryBuilder $inner): void {
            $inner->andWhere('s2.includeInAllItems = true');
        });

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @param \Closure(QueryBuilder, string): void $restrictFeeds narrows the feeds behind an alias, outer and inner */
    private function networkCounts(int $userId, \Closure $restrictFeeds): QueryBuilder
    {
        $qb = $this->unreadEntries($userId)
            ->innerJoin('e.feed', 'nf')
            ->andWhere('nf.network IS NOT NULL')
            ->select('nf.network AS scopeKey', 'COUNT(e.id) AS unreadCount')
            ->groupBy('nf.network');
        $restrictFeeds($qb, 'nf');
        $this->collapseWithin($qb, $userId, static function (QueryBuilder $inner) use ($restrictFeeds): void {
            $inner->innerJoin('e2.feed', 'nf2', 'WITH', 'nf2.network = nf.network');
            $restrictFeeds($inner, 'nf2');
        });

        return $qb;
    }

    /** @return \Closure(QueryBuilder, string): void */
    private static function everyFeed(): \Closure
    {
        return static fn (QueryBuilder $qb, string $feedAlias) => null;
    }

    private function unreadEntries(int $userId): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Subscription::class, 's')
            ->join(Entry::class, 'e', 'ON', 'e.feed = s.feed')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = s.user')
            ->andWhere('s.user = :user')
            ->andWhere(UnreadDql::predicate())
            ->setParameter('user', $userId)
            ->setParameter('notHidden', false, Types::BOOLEAN);
    }

    /** @param callable(QueryBuilder): void $correlateScope keeps the semi-join inside the outer row's scope */
    private function collapseWithin(QueryBuilder $qb, int $userId, callable $correlateScope): void
    {
        $this->collapse->apply(
            $qb,
            static function (QueryBuilder $inner, EntryAliases $aliases) use ($correlateScope): void {
                $inner->andWhere(UnreadDql::predicate($aliases));
                $correlateScope($inner);
            },
            $userId,
        );
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
