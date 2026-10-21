<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\FeedGroup;
use Doctrine\ORM\QueryBuilder;

/** The sidebar's two sections: Tags holds every tagged subscription, Feeds the untagged ones on no network. */
final class FeedGroupDql
{
    public static function restrict(
        QueryBuilder $qb,
        string $subscriptionAlias,
        string $feedAlias,
        FeedGroup $group,
    ): void {
        if ($group === FeedGroup::Tags) {
            $qb->andWhere(\sprintf('%s.subscriptionTags IS NOT EMPTY', $subscriptionAlias));

            return;
        }

        $qb->andWhere(\sprintf('%s.subscriptionTags IS EMPTY', $subscriptionAlias))
            ->andWhere(\sprintf('%s.network IS NULL', $feedAlias));
    }

    private function __construct()
    {
    }
}
