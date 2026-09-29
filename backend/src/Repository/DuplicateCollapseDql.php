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
        $inner = $this->entityManager->createQueryBuilder()
            ->select('1')
            ->from(Entry::class, 'e2')
            ->join(Subscription::class, 's2', 'ON', 's2.feed = e2.feed AND s2.user = :user')
            ->leftJoin(EntryState::class, 'es2', 'ON', 'es2.entry = e2 AND es2.user = :user')
            ->andWhere('e2.location.urlHash = e.location.urlHash')
            ->andWhere('e2.id < e.id');
        $applyScope($inner, EntryAliases::collapse());

        $qb->andWhere('e.location.urlHash IS NULL OR NOT EXISTS (' . $inner->getDQL() . ')')
            ->setParameter('user', $userId);
    }
}
