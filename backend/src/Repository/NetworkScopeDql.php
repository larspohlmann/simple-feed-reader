<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\NetworkFeedKind;
use App\Enum\SourceFormat;
use Doctrine\ORM\QueryBuilder;

/** A profile feed is a networked feed not read through an account, as SubscriptionJson::social() classifies it. */
final class NetworkScopeDql
{
    public static function restrict(QueryBuilder $qb, string $feedAlias, NetworkScope $scope): void
    {
        $qb->andWhere(\sprintf('%s.network = :network', $feedAlias))->setParameter('network', $scope->network);
        if ($scope->feedKind === NetworkFeedKind::Profile) {
            $qb->andWhere(\sprintf('%s.sourceFormat NOT IN (:privateFeedFormats)', $feedAlias))
                ->setParameter('privateFeedFormats', SourceFormat::SOCIAL);
        }
    }

    private function __construct()
    {
    }
}
