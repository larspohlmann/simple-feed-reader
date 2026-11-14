<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model\RefreshTarget;

final readonly class NeverFetchedTargetModel implements RefreshTargetModelInterface
{
    public function runKey(): string
    {
        return 'never-fetched';
    }
}
