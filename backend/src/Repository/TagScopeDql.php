<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SubscriptionTag;
use Doctrine\ORM\QueryBuilder;

/**
 * A subscription is in a tag's scope when it carries the tag or any tag below it; a semi-join, so a feed
 * carrying several of them appears once.
 */
final class TagScopeDql
{
    /** The caller binds `:tagId`. */
    public static function predicate(string $subscriptionAlias): string
    {
        $join = $subscriptionAlias . 'TagJoin';
        $lineage = $subscriptionAlias . 'ScopedTag';

        return \sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$s JOIN %2$s.tag %3$s%4$s WHERE %2$s.subscription = %5$s AND %6$s)',
            SubscriptionTag::class,
            $join,
            TagLineageDql::alias($lineage, 0),
            TagLineageDql::ancestorJoins($lineage),
            $subscriptionAlias,
            TagLineageDql::reaches($lineage, ':tagId'),
        );
    }

    public static function restrict(QueryBuilder $qb, string $subscriptionAlias, int $tagId): void
    {
        $qb->andWhere(self::predicate($subscriptionAlias))->setParameter('tagId', $tagId);
    }

    private function __construct()
    {
    }
}
