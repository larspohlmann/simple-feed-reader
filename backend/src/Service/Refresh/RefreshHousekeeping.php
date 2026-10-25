<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Service\Feed\OrphanedFeedReclaimer;
use App\Service\Refresh\Model\RefreshRequestModel;
use App\Service\Retention\EntryPruner;
use App\Service\SubscriptionHistory\SubscriptionHistoryRecorder;
use Psr\Log\LoggerInterface;

/** The work only a pruning (maintenance) refresh does; a user-triggered refresh skips it to stay fast. */
final readonly class RefreshHousekeeping
{
    public function __construct(
        private OrphanedFeedReclaimer $orphanedFeeds,
        private EntryPruner $pruner,
        private SubscriptionHistoryRecorder $history,
        private LoggerInterface $logger,
    ) {
    }

    public function reclaimOrphanedFeeds(RefreshRequestModel $request): void
    {
        if (!$request->prune) {
            return;
        }

        $reclaimed = $this->orphanedFeeds->reclaimAll();
        if ($reclaimed > 0) {
            $this->logger->info('Reclaimed orphaned feeds', ['count' => $reclaimed]);
        }
    }

    public function pruneEntries(RefreshRequestModel $request): int
    {
        if (!$request->prune) {
            return 0;
        }

        $recorded = $this->history->recordCompletedDays();
        if ($recorded > 0) {
            $this->logger->info('Recorded subscription history days', ['count' => $recorded]);
        }

        return $this->pruner->prune();
    }
}
