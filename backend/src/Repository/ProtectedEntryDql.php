<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryShare;
use App\Entity\EntryState;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

/** An entry is protected iff any user favorited, kept or shared it. */
final class ProtectedEntryDql
{
    public static function exclude(QueryBuilder $qb, string $entryAlias): void
    {
        $qb->andWhere(\sprintf(
            'NOT EXISTS (
                SELECT IDENTITY(protector.user) FROM %1$s protector
                WHERE protector.entry = %3$s
                  AND (protector.isFavorite = :protectedFlag OR protector.isKept = :protectedFlag)
            ) AND NOT EXISTS (SELECT IDENTITY(share.user) FROM %2$s share WHERE share.entry = %3$s)',
            EntryState::class,
            EntryShare::class,
            $entryAlias,
        ))->setParameter('protectedFlag', true, Types::BOOLEAN);
    }

    private function __construct()
    {
    }
}
