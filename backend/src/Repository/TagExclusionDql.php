<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Enum\InclusionSurface;

/** Holds back a subscription that carries a tag switched out of a surface, or a tag below one; exclusion wins. */
final class TagExclusionDql
{
    public static function notExcluded(string $subscriptionAlias, InclusionSurface $surface): string
    {
        $join = $subscriptionAlias . 'ExclusionJoin';
        $lineage = $subscriptionAlias . 'ExcludingTag';
        $switchedOff = array_map(
            static fn (int $level): string => \sprintf(
                '%s.inclusions.%s = false',
                TagLineageDql::alias($lineage, $level),
                $surface->switchField(),
            ),
            range(0, Tag::MAX_DEPTH),
        );

        return \sprintf(
            'NOT EXISTS (SELECT 1 FROM %1$s %2$s JOIN %2$s.tag %3$s%4$s WHERE %2$s.subscription = %5$s AND (%6$s))',
            SubscriptionTag::class,
            $join,
            TagLineageDql::alias($lineage, 0),
            TagLineageDql::ancestorJoins($lineage),
            $subscriptionAlias,
            implode(' OR ', $switchedOff),
        );
    }

    private function __construct()
    {
    }
}
