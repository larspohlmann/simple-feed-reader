<?php

declare(strict_types=1);

namespace App\Service\Completion;

use App\Entity\Entry;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Enum\ShareKind;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Share\EntryShareClassifier;
use App\Service\Share\PendingEmbed\PendingEmbedInterface;
use App\Service\Share\Support\SharedLinks;
use Doctrine\ORM\EntityManagerInterface;

/** Classifies each new entry and queues the ones whose embed or link cards are still to come. */
final readonly class ShareEnrichmentQueue
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntrySanitizer $sanitizer,
        private NaiveUtcClock $clock,
        private EntryShareClassifier $classifier,
        private PendingEmbedInterface $pendingEmbed,
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
        if ($this->pendingEmbed->awaitsEmbed($entry)) {
            $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::AppView, $queuedAt));

            return;
        }
        if ($entry->getShare()->kind !== ShareKind::Link) {
            return;
        }
        if ($entry->isTitleDerived()) {
            $this->linkBareUrls($entry);
        }
        $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::LinkCards, $queuedAt));
    }

    private function linkBareUrls(Entry $entry): void
    {
        $linkedHtml = SharedLinks::of((string) $entry->getContentHtml())->linkedHtml;
        if ($linkedHtml !== $entry->getContentHtml()) {
            $entry->setContentHtml($this->sanitizer->sanitize($linkedHtml));
        }
    }
}
