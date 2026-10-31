<?php

declare(strict_types=1);

namespace App\Service\SelfUpdate\Model;

enum SelfUpdatePhase: string
{
    case Idle = 'idle';
    case Requested = 'requested';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isUnderway(): bool
    {
        return self::Requested === $this || self::Running === $this;
    }
}
