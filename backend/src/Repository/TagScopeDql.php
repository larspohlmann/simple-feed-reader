<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SubscriptionTag;
use Doctrine\ORM\QueryBuilder;

/**
 * A subscription is in a tag's scope when it carries the tag or a child of it; a semi-join, so a feed
 * carrying both appears once.
 */
final class TagScopeDql
{
    /** The caller binds `:tagId`. */
    public static function predicate(string $subscriptionAlias): string
    {
        $join = $subscriptionAlias . 'TagJoin';
        $tag = $subscriptionAlias . 'ScopedTag';

        return \sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$s JOIN %2$s.tag %3$s'
            . ' WHERE %2$s.subscription = %4$s AND (%3$s.id = :tagId OR %3$s.parent = :tagId))',
            SubscriptionTag::class,
            $join,
            $tag,
            $subscriptionAlias,
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
