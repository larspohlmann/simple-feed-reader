<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\InclusionSurface;
use Doctrine\ORM\QueryBuilder;

/** A subscription counts in a surface while it, its network and every tag it carries are switched in; exclusion wins. */
final class InclusionScopeDql
{
    public static function restrict(QueryBuilder $qb, string $subscription, InclusionSurface $surface): void
    {
        $qb->andWhere(\sprintf('%s.inclusions.%s = true', $subscription, $surface->switchField()))
            ->andWhere(TagExclusionDql::notExcluded($subscription, $surface));
        if ($surface->hasNetworkExclusions()) {
            $qb->andWhere(NetworkExclusionDql::notExcluded($subscription, $surface));
        }
    }

    private function __construct()
    {
    }
}
