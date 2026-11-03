<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryShare;

/** The one DQL definition of "the reader shared it"; the caller binds :user. */
final class EntryShareDql
{
    public static function exists(EntryAliases $aliases): string
    {
        return self::existsFor($aliases->entry);
    }

    public static function existsFor(string $entryAlias): string
    {
        $share = 'share' . ucfirst($entryAlias);

        return \sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$s WHERE %2$s.entry = %3$s AND %2$s.user = :user)',
            EntryShare::class,
            $share,
            $entryAlias,
        );
    }
}
