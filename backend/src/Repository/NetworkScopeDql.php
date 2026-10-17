<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\NetworkFeedKind;
use App\Enum\SourceFormat;
use Doctrine\ORM\QueryBuilder;

/** A profile feed is a networked feed of none of SourceFormat::NOT_PROFILES. */
final class NetworkScopeDql
{
    public static function restrict(QueryBuilder $qb, string $feedAlias, NetworkScope $scope): void
    {
        $qb->andWhere(\sprintf('%s.network = :network', $feedAlias))->setParameter('network', $scope->network);
        if ($scope->feedKind === NetworkFeedKind::Profile) {
            $qb->andWhere(\sprintf('%s.sourceFormat NOT IN (:notProfiles)', $feedAlias))
                ->setParameter('notProfiles', SourceFormat::NOT_PROFILES);
        }
    }

    private function __construct()
    {
    }
}
