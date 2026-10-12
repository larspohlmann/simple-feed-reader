<?php

declare(strict_types=1);

namespace App\Enum;

enum SocialAccountStatus: string
{
    case Connected = 'connected';
    case NeedsReconnect = 'needs_reconnect';
}
