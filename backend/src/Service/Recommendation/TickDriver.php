<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Which driver is ticking the run, so the advancer can size a wave to the
 * regime it runs in (#344): the worker owns its process and may send the full
 * per-connection concurrency; a poll tick is a web request, so it clamps to
 * POLL_MAX_CONCURRENCY to keep one request bounded.
 */
enum TickDriver
{
    case Worker;
    case Poll;
}
