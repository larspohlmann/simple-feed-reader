<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Feed;
use App\Service\Discovery\DiscoveredFeed;
use App\Service\EntryIngestor;
use App\Service\FeedIngestContext;
use App\Service\FeedScheduler;
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

    public function __construct(
        private EntryIngestor $ingestor,
        private FeedScheduler $scheduler,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
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

        $created = $this->ingestor->ingest(
            $feed,
            $discovered->document,
            new FeedIngestContext($this->clock->now(), null),
        );
        $feed->setEtag($this->truncate($discovered->etag, self::ETAG_MAX));
        $feed->setLastModified($this->truncate($discovered->lastModified, self::LAST_MODIFIED_MAX));
        $this->scheduler->recordSuccess($feed, $created);
        $this->em->flush();

        return $created;
    }

    private function truncate(?string $value, int $max): ?string
    {
        return null === $value ? null : mb_substr($value, 0, $max);
    }
}
