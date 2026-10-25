<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use Doctrine\ORM\QueryBuilder;

/** A tag and its ancestors as DQL aliases: `<prefix>0` is the tag, `<prefix>1` its parent, up to Tag::MAX_DEPTH. */
final class TagLineageDql
{
    public static function alias(string $prefix, int $level): string
    {
        return $prefix . $level;
    }

    public static function ancestorJoins(string $prefix): string
    {
        $joins = '';
        foreach (range(1, Tag::MAX_DEPTH) as $level) {
            $joins .= \sprintf(
                ' LEFT JOIN %s.parent %s',
                self::alias($prefix, $level - 1),
                self::alias($prefix, $level),
            );
        }

        return $joins;
    }

    public static function joinAncestors(QueryBuilder $qb, string $prefix): void
    {
        foreach (range(1, Tag::MAX_DEPTH) as $level) {
            $qb->leftJoin(self::alias($prefix, $level - 1) . '.parent', self::alias($prefix, $level));
        }
    }

    /** True when the tag or one of its ancestors has the id $tagIdExpression names. */
    public static function reaches(string $prefix, string $tagIdExpression): string
    {
        $matches = array_map(
            static fn (int $level): string => \sprintf('%s.id = %s', self::alias($prefix, $level), $tagIdExpression),
            range(0, Tag::MAX_DEPTH),
        );

        return '(' . implode(' OR ', $matches) . ')';
    }

    private function __construct()
    {
    }
}
