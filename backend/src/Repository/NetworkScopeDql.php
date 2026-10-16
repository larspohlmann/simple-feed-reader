<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\NetworkFeedKind;
use App\Enum\SourceFormat;
use Doctrine\ORM\QueryBuilder;

/** A profile feed is a networked feed whose source format is not a private (account-read) one. */
final class NetworkScopeDql
{
    public static function restrict(QueryBuilder $qb, string $feedAlias, NetworkScope $scope): void
    {
        $qb->andWhere(\sprintf('%s.network = :network', $feedAlias))->setParameter('network', $scope->network);
        if ($scope->feedKind === NetworkFeedKind::Profile) {
            $qb->andWhere(\sprintf('%s.sourceFormat NOT IN (:socialFormats)', $feedAlias))
                ->setParameter('socialFormats', SourceFormat::SOCIAL);
        }
    }

    private function __construct()
    {
    }
}
