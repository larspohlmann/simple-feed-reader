<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

enum TimelineOutcome
{
    case Fetched;
    case Throttled;
    case Failed;
    /** Not asked: the account was throttled earlier in this refresh. */
    case AccountThrottled;
    /** Not asked: the account needs reconnecting. */
    case AccountPaused;
}
