<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialFeed;
use App\Enum\NetworkFeedKind;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use Doctrine\ORM\QueryBuilder;

/** A profile feed is a networked feed of none of SourceFormat::NOT_PROFILES, or an author feed: one person. */
final class NetworkScopeDql
{
    public static function restrict(QueryBuilder $qb, string $feedAlias, NetworkScope $scope): void
    {
        $qb->andWhere(\sprintf('%s.network = :network', $feedAlias))->setParameter('network', $scope->network);
        match ($scope->feedKind) {
            null => null,
            NetworkFeedKind::Profile => self::restrictToProfiles($qb, $feedAlias),
            NetworkFeedKind::Search => self::restrictToSearches($qb, $feedAlias),
        };
    }

    public static function restrictToProfiles(QueryBuilder $qb, string $feedAlias): void
    {
        PublicOrAuthorFeedDql::restrict($qb, $feedAlias, SourceFormat::NOT_PROFILES);
    }

    private static function restrictToSearches(QueryBuilder $qb, string $feedAlias): void
    {
        $qb->andWhere(\sprintf(
            'EXISTS (SELECT %1$sSearch.id FROM %2$s %1$sSearch WHERE %1$sSearch.feed = %1$s'
            . ' AND %1$sSearch.kind = :searchFeedKind)',
            $feedAlias,
            SocialFeed::class,
        ))->setParameter('searchFeedKind', SocialFeedKind::Search);
    }

    private function __construct()
    {
    }
}
