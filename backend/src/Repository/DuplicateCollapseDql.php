<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Hides every copy of an article but the lowest-id one in scope, as a plain WHERE so a page still holds `limit`
 * rows. The semi-join takes the outer query's own scope callback: a survivor picked outside the scope leaves a hole.
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
        $inner = $this->copyLookup($applyScope);

        $qb->andWhere('e.location.urlHash IS NULL OR NOT EXISTS (' . $inner->getDQL() . ')')
            ->setParameter('user', $userId);
    }

    /**
     * A count probes every row, so hashes held once are skipped first; a page probes few, where that costs more.
     *
     * @param callable(QueryBuilder, EntryAliases): void $applyScope
     */
    public function applyToCount(QueryBuilder $qb, callable $applyScope, int $userId): void
    {
        $inner = $this->copyLookup($applyScope);
        $duplicatedHashes = $this->entityManager->createQueryBuilder()
            ->select('e3.location.urlHash')
            ->from(Entry::class, 'e3')
            ->join(Subscription::class, 's3', 'ON', 's3.feed = e3.feed AND s3.user = :user')
            ->andWhere('e3.location.urlHash IS NOT NULL')
            ->groupBy('e3.location.urlHash')
            ->having('COUNT(e3.id) > 1');

        $qb->andWhere(
            'e.location.urlHash IS NULL'
            . ' OR e.location.urlHash NOT IN (' . $duplicatedHashes->getDQL() . ')'
            . ' OR NOT EXISTS (' . $inner->getDQL() . ')'
        )
            ->setParameter('user', $userId);
    }

    /**
     * @param callable(QueryBuilder, EntryAliases): void $applyScope
     */
    private function copyLookup(callable $applyScope): QueryBuilder
    {
        $inner = $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(Entry::class, 'e2')
            ->join(Subscription::class, 's2', 'ON', 's2.feed = e2.feed AND s2.user = :user')
            ->leftJoin(EntryState::class, 'es2', 'ON', 'es2.entry = e2 AND es2.user = :user')
            ->andWhere('e2.location.urlHash = e.location.urlHash')
            ->andWhere('e2.id < e.id');
        $applyScope($inner, EntryAliases::collapse());

        return $inner;
    }
}
