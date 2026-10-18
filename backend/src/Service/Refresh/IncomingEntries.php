<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Completion\ShareEnrichmentQueue;
use App\Service\Refresh\Model\IncomingFeedModel;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\TimelineSelfThreads;

/** What a refresh does with a page's entries besides storing them: a timeline's threads, then each share. */
final readonly class IncomingEntries
{
    public function __construct(
        private ShareEnrichmentQueue $enrichmentQueue,
        private TimelineSelfThreads $selfThreads,
    ) {
    }

    public function ofTimeline(Feed $feed, TimelinePageModel $page): IncomingFeedModel
    {
        $plan = $this->selfThreads->plan($feed, $page);

        return new IncomingFeedModel($plan->parsed, $page->knownShares, $plan->threads);
    }

    /**
     * Queued first: settling the shares may reset a new root's share kind, which a folded reply's card then raises.
     *
     * @param list<Entry> $createdEntries persisted, not yet flushed: the caller flushes
     */
    public function settle(Feed $feed, IncomingFeedModel $incoming, array $createdEntries): void
    {
        $this->enrichmentQueue->queueWithKnownShares($createdEntries, $incoming->knownShares);
        $this->selfThreads->fold($feed, $incoming->threads, $createdEntries);
    }
}
