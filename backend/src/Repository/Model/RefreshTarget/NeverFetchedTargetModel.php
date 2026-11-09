<?php

declare(strict_types=1);

namespace App\Repository\Model\RefreshTarget;

use Doctrine\ORM\QueryBuilder;

final readonly class NeverFetchedTargetModel implements RefreshTargetModelInterface
{
    public function restrict(QueryBuilder $qb, string $feedAlias, ?int $userId): void
    {
        $qb->andWhere(\sprintf('%s.fetchSchedule.lastFetchedAt IS NULL', $feedAlias));
    }

    public function runKey(): string
    {
        return 'never-fetched';
    }
}
