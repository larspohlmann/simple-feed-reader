<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model\RefreshTarget;

use App\Enum\FeedGroup;

final readonly class FeedGroupTargetModel implements RefreshTargetModelInterface
{
    public function __construct(public FeedGroup $feedGroup)
    {
    }

    public function runKey(): string
    {
        return 'group-' . $this->feedGroup->value;
    }
}
