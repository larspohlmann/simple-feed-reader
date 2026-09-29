<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Model;

use App\Service\Recommendation\Run\WorkerPresence;

/**
 * The regimes that drive runs on the install's behalf, each under its own heartbeat name (WorkerPresence says why the
 * names stay apart). A browser poll tick is not one: claiming liveness for it would stop every other tab driving.
 */
enum RecommendationDriverKind: string
{
    case PersistentWorker = 'recommendation-sweep';
    case OnDemandDrainer = 'recommendation-drain-sweep';
    case CronSweep = 'recommendation-cron-sweep';

    public function heartbeatName(): string
    {
        return $this->value;
    }

    /**
     * A one-pass driver clears its key on exit, or polls would defer to it for the whole freshness window. The
     * persistent worker's key only ages out: nobody else may clear a running worker's key.
     */
    public function surrendersItsKeyOnExit(): bool
    {
        return self::PersistentWorker !== $this;
    }
}
