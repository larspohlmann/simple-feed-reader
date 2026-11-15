<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;

/** Fetches a quote post's shared link with the entry, so a page of quote posts reads no link one by one. */
final readonly class QuotedLinkJoin
{
    public static function onto(QueryBuilder $queryBuilder, string $entryAlias): QueryBuilder
    {
        $linkAlias = $entryAlias . '_quoted_link';

        return $queryBuilder->leftJoin($entryAlias . '.quotedLink', $linkAlias)->addSelect($linkAlias);
    }
}
