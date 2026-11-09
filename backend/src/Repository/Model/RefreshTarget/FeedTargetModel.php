<?php

declare(strict_types=1);

namespace App\Repository\Model\RefreshTarget;

use Doctrine\ORM\QueryBuilder;

final readonly class FeedTargetModel implements RefreshTargetModelInterface
{
    public function __construct(public int $feedId)
    {
    }

    public function restrict(QueryBuilder $qb, string $feedAlias, ?int $userId): void
    {
        $qb->andWhere(\sprintf('%s.id = :feedId', $feedAlias))->setParameter('feedId', $this->feedId);
    }

    public function runKey(): string
    {
        return 'feed-' . $this->feedId;
    }
}
