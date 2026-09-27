<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;

final readonly class NextPosition
{
    /** `$list` selects one ordered list's rows; its root entity has a `position` field. */
    public function in(QueryBuilder $list): int
    {
        $alias = $list->getRootAliases()[0];
        $max = $list->select(sprintf('MAX(%s.position)', $alias))->getQuery()->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
