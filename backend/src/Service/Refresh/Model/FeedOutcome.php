<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

enum FeedOutcome
{
    case Fetched;
    case NotModified;
    case Failed;
    /** The site is rationing requests; the feed is healthy and will be asked again shortly. */
    case Throttled;

    /**
     * Whether the feed answered with something to show. Only those earn a favicon lookup: for any other, a homepage
     * round trip goes to a feed that may never recover, or to a host that just asked for fewer requests.
     */
    public function broughtContent(): bool
    {
        return self::Fetched === $this || self::NotModified === $this;
    }
    /** Persistence failed; the EntityManager may be closed, so the run must stop. */
    case Aborted;
}
