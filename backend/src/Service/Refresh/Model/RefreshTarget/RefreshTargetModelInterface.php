<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model\RefreshTarget;

interface RefreshTargetModelInterface
{
    public function runKey(): string;
}
