<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Feed;
use App\Service\EntryIngestor;
use App\Service\FeedScheduler;
use App\Service\Parser\ParsedFeed;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores the document discovery already read as a feed's first fetch.
 *
 * Without this a new subscription is an empty shelf until some later refresh
 * fetches the very URL discovery just downloaded, parsed and threw away. That
 * second request lands seconds after the first, which is precisely what sites
 * rationing requests answer with 429 — leaving the feed empty and, before #290,
 * marked as failing (see FeedScheduler::recordThrottled).
 *
 * Recording it as a real fetch also puts the feed on the normal schedule, so
 * the next sweep leaves it alone instead of asking the same host again.
 */
final readonly class FirstFetchRecorder
{
    public function __construct(
        private EntryIngestor $ingestor,
        private FeedScheduler $scheduler,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Only for a feed nobody has fetched yet: a shared row somebody else
     * already refreshed has a schedule and a history of its own, and this
     * document — read for a different user's subscribe — is no reason to
     * rewrite either.
     *
     * @throws \DateMalformedStringException
     */
    public function record(Feed $feed, ParsedFeed $document): void
    {
        if (null !== $feed->getLastFetchedAt()) {
            return;
        }

        $created = $this->ingestor->ingest($feed, $document);
        $this->scheduler->recordSuccess($feed, $created);
        $this->em->flush();
    }
}
