<?php

declare(strict_types=1);

namespace App\Enum;

enum PrivateFeedSyncSkip: string
{
    case Unreachable = 'unreachable';
    case Incomplete = 'incomplete';
    case NeedsReconnect = 'needs_reconnect';
    case Failed = 'failed';
}
