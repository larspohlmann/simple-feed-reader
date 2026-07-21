<?php

declare(strict_types=1);

namespace App\Service\Refresh;

enum FeedOutcome
{
    case Fetched;
    case NotModified;
    case Failed;
    /** Persistence failed; the EntityManager may be closed, so the run must stop. */
    case Aborted;
}
