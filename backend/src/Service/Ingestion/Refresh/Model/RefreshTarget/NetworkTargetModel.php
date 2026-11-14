<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model\RefreshTarget;

use App\Repository\NetworkScope;

final readonly class NetworkTargetModel implements RefreshTargetModelInterface
{
    public function __construct(public NetworkScope $networkScope)
    {
    }

    public function runKey(): string
    {
        return 'set-network-' . $this->networkScope->toRunKey();
    }
}
