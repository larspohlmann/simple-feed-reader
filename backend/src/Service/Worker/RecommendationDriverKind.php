<?php

declare(strict_types=1);

namespace App\Service\Worker;

/**
 * Which regime is driving recommendation runs, and under which heartbeat name
 * it says so (#371 follow-up). The cases are the regimes that advance runs on
 * somebody else's behalf: the always-on worker container's ten-second firing,
 * the detached drain command a web request spawns on an install that has none,
 * and the cron sweep a worker-less install drives through /maintenance/tick.
 *
 * A browser poll tick is deliberately NOT one of them: it advances the run of
 * the very account that is watching it, and claiming worker liveness for it
 * would tell every other tab to stop driving (#433).
 *
 * A kind rather than a collaborator per regime: the only thing that ever
 * differed between them was this name, and WorkerPresence is the one place
 * that turns a name into liveness. Why the names must stay apart is written
 * out on {@see WorkerPresence}.
 */
enum RecommendationDriverKind: string
{
    case PersistentWorker = 'recommendation-sweep';
    case OnDemandDrainer = 'recommendation-drain-sweep';
    case CronSweep = 'recommendation-cron-sweep';

    /**
     * The backing value IS the heartbeat row's name; this method is what says
     * so at the call sites, where a bare `->value` would not.
     */
    public function heartbeatName(): string
    {
        return $this->value;
    }

    /**
     * A driver that exists for one pass surrenders its key on the way out: it
     * knows when it is done, and its whole freshness window would otherwise
     * keep every browser deferring to a process that has already exited.
     * A persistent worker never does: it simply stops touching its row and
     * ages out, and a running one must not have its key cleared by anybody
     * else.
     */
    public function surrendersItsKeyOnExit(): bool
    {
        return self::PersistentWorker !== $this;
    }
}
