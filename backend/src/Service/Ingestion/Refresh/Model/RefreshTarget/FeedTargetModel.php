<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\Model\RefreshTarget;

final readonly class FeedTargetModel implements RefreshTargetModelInterface
{
    public function __construct(public int $feedId)
    {
    }

    public function runKey(): string
    {
        return 'feed-' . $this->feedId;
    }
}
