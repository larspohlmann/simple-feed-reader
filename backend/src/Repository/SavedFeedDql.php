<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\SourceFormat;
use Doctrine\ORM\QueryBuilder;

final class SavedFeedDql
{
    public static function exclude(QueryBuilder $qb, string $feedAlias): void
    {
        $qb->andWhere(\sprintf('%s.sourceFormat <> :savedFormat', $feedAlias))
            ->setParameter('savedFormat', SourceFormat::SAVED);
    }

    public static function only(QueryBuilder $qb, string $feedAlias): void
    {
        $qb->andWhere(\sprintf('%s.sourceFormat = :savedFormat', $feedAlias))
            ->setParameter('savedFormat', SourceFormat::SAVED);
    }

    private function __construct()
    {
    }
}
