<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use Doctrine\ORM\QueryBuilder;

/** A feed of none of the excluded formats, or an author feed, which follows one person a public RSS feed names too. */
final class PublicOrAuthorFeedDql
{
    /** @param list<string> $excludedFormats */
    public static function restrict(QueryBuilder $qb, string $feedAlias, array $excludedFormats): void
    {
        $qb->andWhere(\sprintf(
            '(%1$s.sourceFormat NOT IN (:excludedFormats) OR (%1$s.sourceFormat = :authorFormat'
            . ' AND EXISTS (SELECT %1$sAuthor.id FROM %2$s %1$sAuthor'
            . ' WHERE %1$sAuthor.feed = %1$s AND %1$sAuthor.kind = :authorKind)))',
            $feedAlias,
            SocialFeed::class,
        ))
            ->setParameter('excludedFormats', $excludedFormats)
            ->setParameter('authorFormat', SourceFormat::BLUESKY_API)
            ->setParameter('authorKind', SocialFeedKind::Author);
    }

    private function __construct()
    {
    }
}
