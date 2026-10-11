<?php

declare(strict_types=1);

namespace App\Service\Completion;

use App\Entity\Entry;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Ingest\Support\AtPostUri;
use App\Service\LinkCard\Model\SharedLinksModel;
use App\Service\LinkCard\Support\SharedLinks;
use App\Service\Sanitize\EntrySanitizer;
use Doctrine\ORM\EntityManagerInterface;

/** Queues the posts whose embed or link cards are still to come; a post that shares links gets its bare URLs linked. */
final readonly class ShareEnrichmentQueue
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntrySanitizer $sanitizer,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @param list<Entry> $createdEntries persisted, not yet flushed: the caller flushes */
    public function queue(array $createdEntries): void
    {
        $queuedAt = $this->clock->now();
        foreach ($createdEntries as $entry) {
            $this->queueCreated($entry, $queuedAt);
        }
    }

    private function queueCreated(Entry $entry, \DateTimeImmutable $queuedAt): void
    {
        if (AtPostUri::matches($entry->getGuid())) {
            $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::AppView, $queuedAt));

            return;
        }
        if (!$entry->isTitleDerived()) {
            return;
        }
        $shared = SharedLinks::of((string) $entry->getContentHtml());
        if ($shared->urls !== []) {
            $this->storeLinked($entry, $shared);
            $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::LinkCards, $queuedAt));
        }
    }

    private function storeLinked(Entry $entry, SharedLinksModel $shared): void
    {
        if ($shared->linkedHtml !== $entry->getContentHtml()) {
            $entry->setContentHtml($this->sanitizer->sanitize($shared->linkedHtml));
        }
    }
}
