<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Entry;
use App\Entity\EntryGuid;
use App\Entity\Feed;
use App\Service\Completion\ShareEnrichmentQueue;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Refresh\Model\IncomingFeedModel;
use App\Service\Social\DeletedPostTombstones;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\TimelineSelfThreads;

/** What a refresh does with a page's entries besides storing them: deleted posts out, threads, each share. */
final readonly class IncomingEntries
{
    public function __construct(
        private ShareEnrichmentQueue $enrichmentQueue,
        private TimelineSelfThreads $selfThreads,
        private DeletedPostTombstones $tombstones,
    ) {
    }

    public function withoutDeletedPosts(Feed $feed, IncomingFeedModel $incoming): IncomingFeedModel
    {
        if (null === $feed->getNetwork()) {
            return $incoming;
        }
        $buried = array_flip($this->tombstones->buriedIn($feed->requireId()));
        if ([] === $buried) {
            return $incoming;
        }

        return $incoming->withParsed($incoming->parsed->withEntries(array_values(array_filter(
            $incoming->parsed->entries,
            static fn (ParsedEntryModel $entry): bool => !isset($buried[EntryGuid::hashOf($entry->guid)]),
        ))));
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
