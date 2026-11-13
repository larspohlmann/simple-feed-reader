<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\InclusionSurface;
use App\Enum\NetworkExclusionSurface;
use Doctrine\ORM\QueryBuilder;

/** A feed counts for For you while it is switched in, and so is its network and every tag it carries. */
final class ForYouScopeDql
{
    /** Binds only the shared :user, which the duplicate-collapse subquery relies on. */
    public static function restrict(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $qb->andWhere(\sprintf('%s.includeInForYou = true', $aliases->subscription))
            ->andWhere(NetworkExclusionDql::notExcluded($aliases->entry, NetworkExclusionSurface::ForYou))
            ->andWhere(TagExclusionDql::notExcluded($aliases->subscription, InclusionSurface::ForYou));
    }

    private function __construct()
    {
    }
}
