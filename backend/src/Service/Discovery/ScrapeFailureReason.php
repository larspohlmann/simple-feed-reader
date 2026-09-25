<?php

declare(strict_types=1);

namespace App\Service\Discovery;

enum ScrapeFailureReason: string
{
    case Blocked = 'blocked';
    case Throttled = 'throttled';
    case Unreachable = 'unreachable';
    case NotScrapable = 'not_scrapable';
}
