<?php

declare(strict_types=1);

namespace App\Repository\Model\RefreshTarget;

use App\Entity\Subscription;
use App\Repository\TagScopeDql;
use Doctrine\ORM\QueryBuilder;

final readonly class TagTargetModel implements RefreshTargetModelInterface
{
    public function __construct(public int $tagId)
    {
    }

    public function restrict(QueryBuilder $qb, string $feedAlias, ?int $userId): void
    {
        if ($userId === null) {
            throw new \LogicException('A tag belongs to a user; its refresh needs one.');
        }

        $qb->andWhere(\sprintf(
            'EXISTS (SELECT ts.id FROM %s ts WHERE ts.feed = %s AND ts.user = :userId AND %s)',
            Subscription::class,
            $feedAlias,
            TagScopeDql::predicate('ts'),
        ))
            ->setParameter('userId', $userId)
            ->setParameter('tagId', $this->tagId);
    }

    public function runKey(): string
    {
        return 'tag-' . $this->tagId;
    }
}
