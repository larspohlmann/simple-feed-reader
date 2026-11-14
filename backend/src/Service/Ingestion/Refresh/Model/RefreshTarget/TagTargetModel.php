<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh\Model\RefreshTarget;

final readonly class TagTargetModel implements RefreshTargetModelInterface
{
    public function __construct(public int $tagId)
    {
    }

    public function runKey(): string
    {
        return 'tag-' . $this->tagId;
    }
}
