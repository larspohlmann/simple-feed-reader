<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryShare;
use App\Entity\EntryState;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Hides every copy of an article or an item but the lowest-id one in scope, as a plain WHERE so a page still holds
 * `limit` rows. The semi-join takes the outer query's own scope callback: a survivor picked outside the scope leaves a
 * hole. One semi-join per key: on MySQL an `OR` of the two keys loses both indexes and walks every subscribed entry.
 */
final readonly class DuplicateCollapseDql
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param callable(QueryBuilder, EntryAliases): void $applyScope
     */
    public function apply(QueryBuilder $qb, callable $applyScope, int $userId): void
    {
        $qb->andWhere(\sprintf(
            'e.location.urlHash IS NULL OR NOT EXISTS (%s)',
            $this->buildEarlierCopyDql('location.urlHash', EntryAliases::collapse(), $applyScope),
        ));
        $qb->andWhere(\sprintf(
            'e.canonicalItem IS NULL OR NOT EXISTS (%s)',
            $this->buildEarlierCopyDql('canonicalItem', EntryAliases::buildItemCollapse(), $applyScope),
        ));
        $qb->setParameter('user', $userId);
    }

    /**
     * @param callable(QueryBuilder, EntryAliases): void $applyScope
     */
    private function buildEarlierCopyDql(string $sharedField, EntryAliases $aliases, callable $applyScope): string
    {
        $earlier = $aliases->entry;
        $inner = $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(Entry::class, $earlier)
            ->join(Subscription::class, $aliases->subscription, 'ON', \sprintf(
                '%1$s.feed = %2$s.feed AND %1$s.user = :user',
                $aliases->subscription,
                $earlier,
            ))
            ->leftJoin(EntryState::class, $aliases->state, 'ON', \sprintf(
                '%1$s.entry = %2$s AND %1$s.user = :user',
                $aliases->state,
                $earlier,
            ))
            ->leftJoin(EntryShare::class, $aliases->share, 'ON', \sprintf(
                '%1$s.entry = %2$s AND %1$s.user = :user',
                $aliases->share,
                $earlier,
            ))
            ->andWhere(\sprintf('%1$s.%2$s = e.%2$s', $earlier, $sharedField))
            ->andWhere(\sprintf('%s.id < e.id', $earlier));
        $applyScope($inner, $aliases);

        return $inner->getDQL();
    }
}
