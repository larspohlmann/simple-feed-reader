<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Feed;
use App\Service\Discovery\Model\DiscoveredFeedModel;
use App\Service\Feed\FeedScheduler;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\Pass\FeedIngestContext;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Search\EntryIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Stores the document discovery already read as a feed's first fetch: entries, caching validators and schedule.
 * Fetching it again seconds later is what rationing sites answer with 429 (FeedThrottledException); the favicon
 * waits for the next sweep, so the host is not asked again at once.
 */
final readonly class FirstFetchRecorder
{
    /**
     * Bounds the subscribe request, not retention: a whole-archive feed (841 items in #384) would make it crawl.
     * What is cut arrives on the next refresh with the same effective date.
     */
    private const int FIRST_FETCH_MAX_ENTRIES = 200;

    public function __construct(
        private EntryIngestor $ingestor,
        private FeedScheduler $scheduler,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private EntryIndexer $indexer,
    ) {
    }

    /**
     * The number of entries stored, which is the new feed's unread count, so the subscribe need not count it. A feed
     * somebody already fetched is left alone: its schedule and history are its own.
     *
     * @throws \DateMalformedStringException
     */
    public function record(Feed $feed, DiscoveredFeedModel $discovered): int
    {
        if (null !== $feed->getLastFetchedAt()) {
            return 0;
        }

        $createdEntries = $this->ingestor->ingest(
            $feed,
            $this->newest($discovered->document),
            new FeedIngestContext($this->clock->now(), null),
        );
        $feed->recordCacheValidators($discovered->etag, $discovered->lastModified);
        $this->scheduler->recordSuccess($feed, \count($createdEntries));
        $this->entityManager->flush();
        // Index after the flush: an entry has no id before it.
        $this->indexer->index($createdEntries);

        return \count($createdEntries);
    }

    /**
     * The newest FIRST_FETCH_MAX_ENTRIES entries, newest publication first and a null date last; usort is stable, so
     * ties keep the feed's order. Sorted even under the cap: EntryIngestor persists in array order.
     */
    private function newest(ParsedFeedModel $document): ParsedFeedModel
    {
        $entries = $document->entries;
        usort($entries, self::byPublicationDateDescending(...));
        $newest = array_slice($entries, 0, self::FIRST_FETCH_MAX_ENTRIES);

        return $document->withEntries($newest);
    }

    private static function byPublicationDateDescending(ParsedEntryModel $left, ParsedEntryModel $right): int
    {
        if ($left->publishedAt === null) {
            return $right->publishedAt === null ? 0 : 1;
        }
        if ($right->publishedAt === null) {
            return -1;
        }

        return $right->publishedAt <=> $left->publishedAt;
    }
}
