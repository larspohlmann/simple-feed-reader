<?php

declare(strict_types=1);

namespace App\Service\Worker;

/**
 * Which regime is driving recommendation runs, and under which heartbeat name
 * it says so (#371 follow-up). The two cases are the two worker regimes: the
 * always-on worker container's ten-second firing, and the detached drain
 * command a web request spawns on an install that has none.
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

    /**
     * The backing value IS the heartbeat row's name; this method is what says
     * so at the call sites, where a bare `->value` would not.
     */
    public function heartbeatName(): string
    {
        return $this->value;
    }

    /**
     * Only a driver that exists for one drain surrenders its key on the way
     * out. A persistent worker never does: it simply stops touching its row
     * and ages out, and a running one must not have its key cleared by
     * anybody else.
     */
    public function surrendersItsKeyOnExit(): bool
    {
        return self::OnDemandDrainer === $this;
    }
}
