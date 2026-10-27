<?php

declare(strict_types=1);

namespace App\Enum;

enum TranscriptStatus: string
{
    case Pending = 'pending';
    case Filled = 'filled';
    case Failed = 'failed';
}
