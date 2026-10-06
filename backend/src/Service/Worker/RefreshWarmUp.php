<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\DependencyInjection\ProcessLifetimeState;
use App\Service\Refresh\Model\RefreshRequestModel;

/**
 * A freshly started worker finds every feed that came due while it was down; fetched in one batch, that backlog
 * starved web requests on a SQLite install in a qemu VM (#1419). The first firings take smaller batches instead.
 */
#[ProcessLifetimeState('Counts the refresh firings since the worker process started')]
final class RefreshWarmUp
{
    private const array WARM_UP_BATCH_LIMITS = [10, 25];

    private int $firings = 0;

    public function nextBatchLimit(): int
    {
        $batchLimit = self::WARM_UP_BATCH_LIMITS[$this->firings] ?? RefreshRequestModel::DEFAULT_BATCH_LIMIT;
        ++$this->firings;

        return $batchLimit;
    }
}
