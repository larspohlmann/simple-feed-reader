<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Which driver is ticking the run, so the advancer can size a wave to the
 * regime it runs in (#344): the worker owns its process and may send the full
 * per-connection concurrency; a poll tick is a web request, so it clamps to
 * POLL_MAX_CONCURRENCY to keep one request bounded. Also names the driver on
 * RecommendationRunAdvancer's #439 "lock already held" warning, so dev.log
 * says who actually hit the contention instead of leaving every non-Worker
 * caller to read as a poll.
 *
 * Sweep is the maintenance cron's ForYouSweep::sweepOnce() call: like a poll
 * tick it runs inside a bounded web request (the cron hits /maintenance/tick
 * over HTTP), so RecommendationRunAdvancer::effectiveCap() clamps it exactly
 * like Poll -- it is Worker, the one driver that owns its own process, that
 * is the exception needing its own branch, not the other two.
 */
enum TickDriver
{
    case Worker;
    case Poll;
    case Sweep;
}
