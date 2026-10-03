<?php

declare(strict_types=1);

namespace App\Enum;

/** What started a profile run; only a scheduled one may skip the model call when its inputs are unchanged. */
enum ProfileRunTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Recommendation = 'recommendation';
}
