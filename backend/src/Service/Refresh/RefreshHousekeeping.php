<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Service\OrphanedFeedReclaimer;
use App\Service\Retention\EntryPruner;
use Psr\Log\LoggerInterface;

/** The work only a pruning (maintenance) refresh does; a user-triggered refresh skips it to stay fast. */
final readonly class RefreshHousekeeping
{
    public function __construct(
        private OrphanedFeedReclaimer $orphanedFeeds,
        private EntryPruner $pruner,
        private LoggerInterface $logger,
    ) {
    }

    /** Runs before the due query: a feed nobody subscribes to must not cost the run an HTTP request. */
    public function reclaimOrphanedFeeds(RefreshRequest $request): void
    {
        if (!$request->prune) {
            return;
        }

        $reclaimed = $this->orphanedFeeds->reclaimAll();
        if ($reclaimed > 0) {
            $this->logger->info('Reclaimed orphaned feeds', ['count' => $reclaimed]);
        }
    }

    public function pruneEntries(RefreshRequest $request): int
    {
        return $request->prune ? $this->pruner->prune() : 0;
    }
}
