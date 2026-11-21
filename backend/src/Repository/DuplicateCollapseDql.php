<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryShare;
use App\Entity\EntryState;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

/**
 * Hides every copy of an article or an item but the lowest-id one in scope, as a plain WHERE so a page still holds
 * `limit` rows. A cross-post group keeps only its earliest-published member, judged in scope without the read filter.
 * The semi-join takes the outer query's own scope callback: a survivor picked outside the scope leaves a hole.
 * One semi-join per key: on MySQL an `OR` of the keys loses the indexes and walks every subscribed entry.
 */
final readonly class DuplicateCollapseDql
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function apply(QueryBuilder $qb, CollapseScope $scope, int $userId): void
    {
        self::hideWhenEarlierExists(
            $qb,
            'e.location.urlHash',
            $this->buildEarlierCopyDql('location.urlHash', EntryAliases::collapse(), $scope->copies),
        );
        self::hideWhenEarlierExists(
            $qb,
            'e.canonicalItem',
            $this->buildEarlierCopyDql('canonicalItem', EntryAliases::itemCollapse(), $scope->copies),
        );
        $item = self::resolveItemAlias($qb);
        self::hideWhenEarlierExists(
            $qb,
            $item . '.crossPostHash',
            $this->buildEarlierCrossPostDql($item, $scope->crossPosts),
        );
        $qb->setParameter('user', $userId);
    }

    private static function hideWhenEarlierExists(QueryBuilder $qb, string $key, string $earlierDql): void
    {
        $qb->andWhere(\sprintf('%s IS NULL OR NOT EXISTS (%s)', $key, $earlierDql));
    }

    private static function resolveItemAlias(QueryBuilder $qb): string
    {
        /** @var array<string, list<Join>> $joins */
        $joins = $qb->getDQLPart('join');
        foreach ($joins['e'] ?? [] as $join) {
            if ($join->getJoin() === 'e.canonicalItem' && $join->getAlias() !== null) {
                return $join->getAlias();
            }
        }
        $qb->leftJoin('e.canonicalItem', 'collapseItem');

        return 'collapseItem';
    }

    /**
     * @param \Closure(QueryBuilder, EntryAliases): void $applyScope
     */
    private function buildEarlierCopyDql(string $sharedField, EntryAliases $aliases, \Closure $applyScope): string
    {
        $earlier = $aliases->entry;
        $inner = $this->buildSubscribedEntryQuery($aliases)
            ->andWhere(\sprintf('%1$s.%2$s = e.%2$s', $earlier, $sharedField))
            ->andWhere(\sprintf('%s.id < e.id', $earlier));
        $applyScope($inner, $aliases);

        return $inner->getDQL();
    }

    /**
     * @param \Closure(QueryBuilder, EntryAliases): void $applyScope
     */
    private function buildEarlierCrossPostDql(string $item, \Closure $applyScope): string
    {
        $aliases = EntryAliases::crossPostCollapse();
        $earlier = $aliases->entry;
        $inner = $this->buildSubscribedEntryQuery($aliases)
            ->join(\sprintf('%s.canonicalItem', $earlier), 'crossPostItem')
            ->andWhere(\sprintf('crossPostItem.crossPostHash = %s.crossPostHash', $item))
            ->andWhere(\sprintf('crossPostItem <> %s', $item))
            // Hole-free only while every copy of an item shares publishedAt; a kind whose copies diverge loses groups.
            ->andWhere(\sprintf(
                '%1$s.dates.publishedAt < e.dates.publishedAt'
                . ' OR (%1$s.dates.publishedAt = e.dates.publishedAt AND %1$s.id < e.id)',
                $earlier,
            ));
        $applyScope($inner, $aliases);

        return $inner->getDQL();
    }

    private function buildSubscribedEntryQuery(EntryAliases $aliases): QueryBuilder
    {
        $entry = $aliases->entry;

        return $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(Entry::class, $entry)
            ->join(Subscription::class, $aliases->subscription, 'ON', \sprintf(
                '%1$s.feed = %2$s.feed AND %1$s.user = :user',
                $aliases->subscription,
                $entry,
            ))
            ->leftJoin(EntryState::class, $aliases->state, 'ON', \sprintf(
                '%1$s.entry = %2$s AND %1$s.user = :user',
                $aliases->state,
                $entry,
            ))
            ->leftJoin(EntryShare::class, $aliases->share, 'ON', \sprintf(
                '%1$s.entry = %2$s AND %1$s.user = :user',
                $aliases->share,
                $entry,
            ));
    }
}
