<?php

declare(strict_types=1);

namespace App\Repository\Model\RefreshTarget;

use Doctrine\ORM\QueryBuilder;

final readonly class EveryFeedTargetModel implements RefreshTargetModelInterface
{
    public function restrict(QueryBuilder $qb, string $feedAlias, ?int $userId): void
    {
    }

    public function runKey(): string
    {
        return 'all';
    }
}
