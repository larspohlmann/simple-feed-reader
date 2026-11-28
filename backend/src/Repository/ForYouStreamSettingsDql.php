<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationSettings;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

/** The account's settings row in DQL, for queries on its stream: no row means the stream runs at the defaults. */
final class ForYouStreamSettingsDql
{
    public const string ALIAS = 'streamSettings';

    public static function joinSettingsRow(QueryBuilder $qb, string $userExpression): void
    {
        $qb->leftJoin(
            RecommendationSettings::class,
            self::ALIAS,
            Join::ON,
            \sprintf('%s.user = %s', self::ALIAS, $userExpression),
        );
    }

    public static function restrictToEnabled(QueryBuilder $qb, string $userExpression): void
    {
        self::joinSettingsRow($qb, $userExpression);
        $qb->andWhere(\sprintf('%1$s.id IS NULL OR %1$s.forYouStream.enabled = true', self::ALIAS));
    }

    private function __construct()
    {
    }
}
