<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialFeed;
use App\Enum\NetworkFeedKind;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use Doctrine\ORM\QueryBuilder;

/** A profile feed is a networked feed read without an account, or an author feed, which follows one person. */
final class NetworkScopeDql
{
    public static function restrict(QueryBuilder $qb, string $feedAlias, NetworkScope $scope): void
    {
        $qb->andWhere(\sprintf('%s.network = :network', $feedAlias))->setParameter('network', $scope->network);
        if ($scope->feedKind === NetworkFeedKind::Profile) {
            $qb->andWhere(\sprintf(
                '(%1$s.sourceFormat NOT IN (:socialFormats) OR EXISTS (SELECT %1$sAuthor.id FROM %2$s %1$sAuthor'
                . ' WHERE %1$sAuthor.feed = %1$s AND %1$sAuthor.kind = :authorKind))',
                $feedAlias,
                SocialFeed::class,
            ))
                ->setParameter('socialFormats', SourceFormat::SOCIAL)
                ->setParameter('authorKind', SocialFeedKind::Author);
        }
    }

    private function __construct()
    {
    }
}
