<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Feed;
use App\Entity\NetworkExclusion;
use App\Enum\NetworkExclusionSurface;

/** Holds back a feed whose network the user has switched out of a surface. */
final class NetworkExclusionDql
{
    /** Binds only the shared :user, which the duplicate-collapse subquery relies on. */
    public static function notExcluded(string $feedOwner, NetworkExclusionSurface $surface): string
    {
        $feed = 'excludedFeed' . ucfirst($feedOwner);
        $exclusion = 'networkExclusion' . ucfirst($feedOwner);

        return \sprintf(
            'NOT EXISTS (SELECT 1 FROM %1$s %2$s WHERE %2$s = %3$s.feed AND '
            . 'EXISTS (SELECT 1 FROM %4$s %5$s WHERE %5$s.user = :user AND %5$s.network = %2$s.network '
            . 'AND %5$s.surface = \'%6$s\'))',
            Feed::class,
            $feed,
            $feedOwner,
            NetworkExclusion::class,
            $exclusion,
            $surface->value,
        );
    }

    private function __construct()
    {
    }
}
