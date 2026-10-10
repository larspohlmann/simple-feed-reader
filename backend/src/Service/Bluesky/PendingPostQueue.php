<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\PendingPostEnrichment;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Ingest\Support\AtPostUri;
use Doctrine\ORM\EntityManagerInterface;

/** Queues new Bluesky posts for the AppView. The caller flushes; only entries created this refresh are queued. */
final readonly class PendingPostQueue
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    /**
     * @param list<Entry> $entries
     *
     * @return int how many of them were Bluesky posts
     */
    public function queue(array $entries): int
    {
        $queuedAt = $this->clock->now();
        $queued = 0;
        foreach ($entries as $entry) {
            if (AtPostUri::matches($entry->getGuid())) {
                $this->entityManager->persist(new PendingPostEnrichment($entry, $queuedAt));
                $queued++;
            }
        }

        return $queued;
    }
}
