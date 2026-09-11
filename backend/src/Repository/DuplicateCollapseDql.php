<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * "Hide every copy but the lowest-id one that is itself in scope." A plain WHERE
 * predicate, so it composes with the keyset cursor and a page still returns
 * `limit` visible rows. The scope is applied to the e2/es2/s2 alias set through
 * the SAME callback the outer query used, so filtering by tag or view scopes the
 * survivor too — see the spec for why carrying the filters prevents holes.
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
            ->andWhere('e2.urlHash = e.urlHash')
            ->andWhere('e2.id < e.id');
        $applyScope($inner, EntryAliases::collapse());

        $qb->andWhere('e.urlHash IS NULL OR NOT EXISTS (' . $inner->getDQL() . ')')
            ->setParameter('user', $userId);
    }
}
