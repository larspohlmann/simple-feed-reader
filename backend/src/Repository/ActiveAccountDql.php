<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\UserStatus;
use Doctrine\ORM\QueryBuilder;

/** `User::isActiveAt()` in DQL, for the background sweeps that act for an account no request has checked. */
final class ActiveAccountDql
{
    public static function restrict(QueryBuilder $qb, string $userAlias, \DateTimeImmutable $now): void
    {
        $qb->andWhere(\sprintf('%s.status = :activeAccountStatus', $userAlias))
            ->andWhere(\sprintf(
                '%1$s.accountLimits.trialEndsAt IS NULL OR %1$s.accountLimits.trialEndsAt > :activeAccountAt',
                $userAlias,
            ))
            ->setParameter('activeAccountStatus', UserStatus::Active)
            ->setParameter('activeAccountAt', $now);
    }

    private function __construct()
    {
    }
}
