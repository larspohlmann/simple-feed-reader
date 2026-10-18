<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\NetworkFeedKind;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use Doctrine\ORM\QueryBuilder;

/** A profile feed is a networked feed of none of SourceFormat::NOT_PROFILES, or an author feed: one person. */
final class NetworkScopeDql
{
    public static function restrict(QueryBuilder $qb, string $feedAlias, NetworkScope $scope): void
    {
        self::restrictToNetwork($qb, $feedAlias, $scope->network);
        self::restrictToKind($qb, $feedAlias, $scope->feedKind);
    }

    public static function restrictToNetwork(QueryBuilder $qb, string $feedAlias, SocialNetwork $network): void
    {
        $qb->andWhere(\sprintf('%s.network = :network', $feedAlias))->setParameter('network', $network);
    }

    public static function restrictToKind(QueryBuilder $qb, string $feedAlias, ?NetworkFeedKind $feedKind): void
    {
        if ($feedKind === NetworkFeedKind::Profile) {
            PublicOrAuthorFeedDql::restrict($qb, $feedAlias, SourceFormat::NOT_PROFILES);
        }
    }

    private function __construct()
    {
    }
}
