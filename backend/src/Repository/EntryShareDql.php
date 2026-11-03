<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryShare;

/** The one DQL definition of "the reader shared it"; the caller binds :user. */
final class EntryShareDql
{
    public static function exists(EntryAliases $aliases): string
    {
        $share = 'share' . ucfirst($aliases->entry);

        return \sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$s WHERE %2$s.entry = %3$s AND %2$s.user = :user)',
            EntryShare::class,
            $share,
            $aliases->entry,
        );
    }
}
