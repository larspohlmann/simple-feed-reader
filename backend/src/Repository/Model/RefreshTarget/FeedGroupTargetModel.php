<?php

declare(strict_types=1);

namespace App\Repository\Model\RefreshTarget;

use App\Entity\Subscription;
use App\Enum\FeedGroup;
use App\Repository\FeedGroupDql;
use Doctrine\ORM\QueryBuilder;

final readonly class FeedGroupTargetModel implements RefreshTargetModelInterface
{
    public function __construct(public FeedGroup $feedGroup)
    {
    }

    public function restrict(QueryBuilder $qb, string $feedAlias, ?int $userId): void
    {
        if ($userId === null) {
            throw new \LogicException('A feed group belongs to a user; its refresh needs one.');
        }

        $subscriptions = $qb->getEntityManager()->createQueryBuilder()
            ->select('gs.id')
            ->from(Subscription::class, 'gs')
            ->andWhere(\sprintf('gs.feed = %s', $feedAlias))
            ->andWhere('gs.user = :userId');
        FeedGroupDql::restrict($subscriptions, 'gs', $feedAlias, $this->feedGroup);

        $qb->andWhere(\sprintf('EXISTS (%s)', $subscriptions->getDQL()))->setParameter('userId', $userId);
    }

    public function runKey(): string
    {
        return 'group-' . $this->feedGroup->value;
    }
}
