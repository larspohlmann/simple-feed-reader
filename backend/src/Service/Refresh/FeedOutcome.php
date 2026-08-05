<?php

declare(strict_types=1);

namespace App\Service\Refresh;

enum FeedOutcome
{
    case Fetched;
    case NotModified;
    case Failed;
    /** The site is rationing requests; the feed is healthy and will be asked again shortly. */
    case Throttled;

    /**
     * Whether the feed answered with something to show. Only those earn a
     * favicon lookup: an icon beside no new content is a homepage round trip
     * per sweep for a feed that may never recover — and for a throttled one it
     * is a second request to a host that just asked for fewer.
     */
    public function broughtContent(): bool
    {
        return self::Fetched === $this || self::NotModified === $this;
    }
    /** Persistence failed; the EntityManager may be closed, so the run must stop. */
    case Aborted;
}
