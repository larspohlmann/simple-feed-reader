<?php

declare(strict_types=1);

namespace App\Service\Completion;

use App\Entity\Entry;
use App\Entity\Share;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Enum\ShareKind;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Ingest\Support\AtPostUri;
use App\Service\LinkCard\Model\SharedLinksModel;
use App\Service\LinkCard\Support\SharedLinks;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Share\EntryShareClassifier;
use Doctrine\ORM\EntityManagerInterface;

/** Classifies each new entry and queues the ones whose embed or link cards are still to come. */
final readonly class ShareEnrichmentQueue
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntrySanitizer $sanitizer,
        private NaiveUtcClock $clock,
        private EntryShareClassifier $classifier,
    ) {
    }

    /** @param list<Entry> $createdEntries persisted, not yet flushed: the caller flushes */
    public function queue(array $createdEntries): void
    {
        $queuedAt = $this->clock->now();
        foreach ($createdEntries as $entry) {
            $this->classifier->classify($entry);
            $this->queueCreated($entry, $queuedAt);
        }
    }

    private function queueCreated(Entry $entry, \DateTimeImmutable $queuedAt): void
    {
        if (AtPostUri::matches($entry->getGuid())) {
            $this->awaitEmbed($entry, $queuedAt);

            return;
        }
        if ($entry->getShare()->kind !== ShareKind::Link) {
            return;
        }
        if ($entry->isTitleDerived()) {
            $this->storeLinked($entry, SharedLinks::of((string) $entry->getContentHtml()));
        }
        $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::LinkCards, $queuedAt));
    }

    private function awaitEmbed(Entry $entry, \DateTimeImmutable $queuedAt): void
    {
        if ($entry->getShare()->isNone()) {
            $entry->setShare(Share::link(null));
        }
        $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::AppView, $queuedAt));
    }

    private function storeLinked(Entry $entry, SharedLinksModel $shared): void
    {
        if ($shared->linkedHtml !== $entry->getContentHtml()) {
            $entry->setContentHtml($this->sanitizer->sanitize($shared->linkedHtml));
        }
    }
}
