<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use Doctrine\ORM\QueryBuilder;

/** A feed of none of the excluded formats, or a private feed following one person a public RSS feed names too. */
final class PublicOrAuthorFeedDql
{
    /** @param list<string> $excludedFormats */
    public static function restrict(QueryBuilder $qb, string $feedAlias, array $excludedFormats): void
    {
        $qb->andWhere(\sprintf(
            '(%1$s.sourceFormat NOT IN (:excludedFormats) OR (%1$s.sourceFormat IN (:personFeedFormats)'
            . ' AND EXISTS (SELECT %1$sPerson.id FROM %2$s %1$sPerson'
            . ' WHERE %1$sPerson.feed = %1$s AND %1$sPerson.kind IN (:personKinds))))',
            $feedAlias,
            SocialFeed::class,
        ))
            ->setParameter('excludedFormats', $excludedFormats)
            ->setParameter('personFeedFormats', SourceFormat::SOCIAL)
            ->setParameter('personKinds', self::personKinds());
    }

    /** @return list<SocialFeedKind> */
    private static function personKinds(): array
    {
        return array_values(array_filter(
            SocialFeedKind::cases(),
            static fn (SocialFeedKind $kind): bool => $kind->followsOnePerson(),
        ));
    }

    private function __construct()
    {
    }
}
