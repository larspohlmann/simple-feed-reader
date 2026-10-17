<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\NetworkFeedKind;
use Doctrine\ORM\QueryBuilder;

/** A profile feed is a networked feed read without an account, or an author feed, which follows one person. */
final class NetworkScopeDql
{
    public static function restrict(QueryBuilder $qb, string $feedAlias, NetworkScope $scope): void
    {
        $qb->andWhere(\sprintf('%s.network = :network', $feedAlias))->setParameter('network', $scope->network);
        if ($scope->feedKind === NetworkFeedKind::Profile) {
            PublicOrAuthorFeedDql::restrict($qb, $feedAlias);
        }
    }

    private function __construct()
    {
    }
}
