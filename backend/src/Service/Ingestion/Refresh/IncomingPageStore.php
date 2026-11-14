<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Refresh;

use App\Entity\Entry;
use App\Entity\EntryGuid;
use App\Entity\Feed;
use App\Service\Social\Completion\ShareEnrichmentQueue;
use App\Service\Ingestion\Feed\FeedScheduler;
use App\Service\Ingestion\Ingest\EntryIngestor;
use App\Service\Ingestion\Ingest\Pass\FeedIngestContext;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Refresh\Model\FeedRefreshResultModel;
use App\Service\Ingestion\Refresh\Model\IncomingFeedModel;
use App\Service\Library\Search\EntryIndexer;
use App\Service\Social\Social\DeletedPostTombstones;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\TimelineSelfThreads;
use Doctrine\ORM\EntityManagerInterface;

/** Stores a page of a feed's entries, whether a fetched body or a social timeline brought it. */
final readonly class IncomingPageStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryIngestor $ingestor,
        private FeedScheduler $scheduler,
        private EntryIndexer $indexer,
        private FeedShareEnrichment $shareEnrichment,
        private ShareEnrichmentQueue $enrichmentQueue,
        private TimelineSelfThreads $selfThreads,
        private DeletedPostTombstones $tombstones,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function storeTimeline(
        Feed $feed,
        TimelinePageModel $page,
        FeedIngestContext $context,
    ): FeedRefreshResultModel {
        $plan = $this->selfThreads->plan($feed, $page);

        return $this->store($feed, new IncomingFeedModel($plan->parsed, $page->knownShares, $plan->threads), $context);
    }

    /** @throws \DateMalformedStringException */
    public function store(Feed $feed, IncomingFeedModel $incoming, FeedIngestContext $context): FeedRefreshResultModel
    {
        $stored = $this->withoutDeletedPosts($feed, $incoming);
        $createdEntries = $this->ingestor->ingest($feed, $stored->parsed, $context);
        // A backfilled image is not new content, so it stays out of the count.
        $this->ingestor->refreshStoredEntries($feed, $stored->parsed);
        $this->scheduler->recordSuccess($feed, \count($createdEntries));
        $this->settle($feed, $stored, $createdEntries);
        $this->entityManager->flush();
        // Only the flush assigns ids, so indexing has to follow it.
        $this->indexer->index($createdEntries);
        $this->shareEnrichment->enrich($feed);

        return FeedRefreshResultModel::fetched(\count($createdEntries));
    }

    private function withoutDeletedPosts(Feed $feed, IncomingFeedModel $incoming): IncomingFeedModel
    {
        if (null === $feed->getNetwork()) {
            return $incoming;
        }
        $buried = array_flip($this->tombstones->buriedIn($feed->requireId()));

        return $incoming->withParsed($incoming->parsed->withEntries(array_values(array_filter(
            $incoming->parsed->entries,
            static fn (ParsedEntryModel $entry): bool => !isset($buried[EntryGuid::hashOf($entry->guid)]),
        ))));
    }

    /**
     * Queued first: settling the shares may reset a new root's share kind, which a folded reply's card then raises.
     *
     * @param list<Entry> $createdEntries persisted, not yet flushed
     */
    private function settle(Feed $feed, IncomingFeedModel $incoming, array $createdEntries): void
    {
        $this->enrichmentQueue->queueWithKnownShares($createdEntries, $incoming->knownShares);
        $this->selfThreads->fold($feed, $incoming->threads, $createdEntries);
    }
}
