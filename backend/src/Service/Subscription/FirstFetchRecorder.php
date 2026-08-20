<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Feed;
use App\Service\Discovery\DiscoveredFeed;
use App\Service\Ingest\EntryIngestor;
use App\Service\Ingest\FeedIngestContext;
use App\Service\FeedScheduler;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Service\Search\EntryIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Stores the document discovery already read as a feed's first fetch.
 *
 * Without this a new subscription is an empty shelf until some later refresh
 * fetches the very URL discovery just downloaded and parsed. That second
 * request lands seconds after the first, which is what sites rationing requests
 * answer with 429 (see FeedThrottledException).
 *
 * It records what the refresh pipeline records for a fetch that delivered:
 * the entries, the caching validators, and the schedule. Only the favicon is
 * left to the next sweep — resolving it here would mean another request to a
 * host we have just finished asking.
 */
final readonly class FirstFetchRecorder
{
    private const int ETAG_MAX = 512;
    private const int LAST_MODIFIED_MAX = 255;

    /**
     * A subscribe inserts every stored entry inside one HTTP request, and a feed
     * that serves its whole archive (841 items for one measured in #384) makes
     * that request crawl. This bounds the request, NOT retention: whatever is cut
     * arrives on the next refresh, with the same effective date it would have had,
     * because an article older than the previous fetch keeps its publication date
     * either way.
     */
    private const int FIRST_FETCH_MAX_ENTRIES = 200;

    public function __construct(
        private EntryIngestor $ingestor,
        private FeedScheduler $scheduler,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private EntryIndexer $indexer,
    ) {
    }

    /**
     * The number of entries stored, which for a feed nobody has read yet is
     * also its unread count — so the subscribe can report it without asking
     * the database to count what it just wrote.
     *
     * Only for a feed nobody has fetched yet: a shared row somebody else
     * already refreshed has a schedule and a history of its own, and this
     * document — read for a different user's subscribe — is no reason to
     * rewrite either.
     *
     * @throws \DateMalformedStringException
     */
    public function record(Feed $feed, DiscoveredFeed $discovered): int
    {
        if (null !== $feed->getLastFetchedAt()) {
            return 0;
        }

        $createdEntries = $this->ingestor->ingest(
            $feed,
            $this->newest($discovered->document),
            new FeedIngestContext($this->clock->now(), null),
        );
        $feed->setEtag($this->truncate($discovered->etag, self::ETAG_MAX));
        $feed->setLastModified($this->truncate($discovered->lastModified, self::LAST_MODIFIED_MAX));
        $this->scheduler->recordSuccess($feed, \count($createdEntries));
        $this->em->flush();
        // See RefreshRunner's identical ordering: an id only exists after this
        // flush, so indexing has to happen after it, not before.
        $this->indexer->index($createdEntries);

        return \count($createdEntries);
    }

    private function truncate(?string $value, int $max): ?string
    {
        return null === $value ? null : mb_substr($value, 0, $max);
    }

    /**
     * The newest FIRST_FETCH_MAX_ENTRIES entries, newest publication first. A
     * null publishedAt sorts last. PHP's usort has been stable since 8.0, so
     * entries sharing a publication date keep the feed's own relative order
     * without any extra bookkeeping here.
     *
     * Sorting runs unconditionally, even when the document is already under
     * the cap: EntryIngestor persists in array order, and a feed's own order
     * is not a publication-date order, so a size-based shortcut here would
     * make "newest first" true only for the feeds large enough to need the
     * cap at all — every subscribe deserves the same guarantee.
     */
    private function newest(ParsedFeed $document): ParsedFeed
    {
        $entries = $document->entries;
        usort($entries, self::byPublicationDateDescending(...));
        $newest = array_slice($entries, 0, self::FIRST_FETCH_MAX_ENTRIES);

        return new ParsedFeed($document->title, $document->siteUrl, $document->description, $newest);
    }

    private static function byPublicationDateDescending(ParsedEntry $left, ParsedEntry $right): int
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
