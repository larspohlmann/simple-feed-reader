<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Which driver is ticking the run, so the advancer can size a wave to its
 * regime (#344): the worker owns its process and may send the full
 * per-connection concurrency; a poll tick is a web request, so it clamps to
 * POLL_MAX_CONCURRENCY to keep one request bounded.
 *
 * Sweep is the maintenance cron's ForYouSweep::sweepOnce() call; like a poll
 * tick it runs inside a bounded web request (the cron hits /maintenance/tick
 * over HTTP), so RecommendationRunAdvancer::effectiveCap() clamps it like Poll.
 * Worker, owning its own process, is the exception needing its own branch.
 */
enum TickDriver
{
    case Worker;
    case Poll;
    case Sweep;
}
