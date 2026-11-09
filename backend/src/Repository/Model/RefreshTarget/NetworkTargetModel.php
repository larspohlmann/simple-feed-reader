<?php

declare(strict_types=1);

namespace App\Repository\Model\RefreshTarget;

use App\Repository\NetworkScope;
use App\Repository\NetworkScopeDql;
use Doctrine\ORM\QueryBuilder;

final readonly class NetworkTargetModel implements RefreshTargetModelInterface
{
    public function __construct(public NetworkScope $networkScope)
    {
    }

    public function restrict(QueryBuilder $qb, string $feedAlias, ?int $userId): void
    {
        NetworkScopeDql::restrict($qb, $feedAlias, $this->networkScope);
    }

    public function runKey(): string
    {
        return 'set-network-' . $this->networkScope->toRunKey();
    }
}
