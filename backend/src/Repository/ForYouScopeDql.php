<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\ForYouNetworkExclusion;
use Doctrine\ORM\QueryBuilder;

/** A feed counts for For you while its subscription is switched in and its network is not switched out. */
final class ForYouScopeDql
{
    /** Binds only the shared :user, which the duplicate-collapse subquery relies on. */
    public static function restrict(QueryBuilder $qb, EntryAliases $aliases): void
    {
        $feed = 'forYouFeed' . ucfirst($aliases->entry);
        $exclusion = 'networkExclusion' . ucfirst($aliases->entry);

        $qb->andWhere(\sprintf(
            '%1$s.includeInForYou = true AND NOT EXISTS (SELECT 1 FROM %2$s %3$s WHERE %3$s = %4$s.feed AND '
            . 'EXISTS (SELECT 1 FROM %5$s %6$s WHERE %6$s.user = :user AND %6$s.network = %3$s.network))',
            $aliases->subscription,
            Feed::class,
            $feed,
            $aliases->entry,
            ForYouNetworkExclusion::class,
            $exclusion,
        ));
    }

    private function __construct()
    {
    }
}
