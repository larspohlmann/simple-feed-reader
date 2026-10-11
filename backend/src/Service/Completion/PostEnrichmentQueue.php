<?php

declare(strict_types=1);

namespace App\Service\Completion;

use App\Entity\Entry;
use App\Entity\PostEnrichment;
use App\Enum\PostEnrichmentKind;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Ingest\Support\AtPostUri;
use App\Service\LinkCard\Model\SharedLinksModel;
use App\Service\LinkCard\Support\SharedLinks;
use App\Service\Sanitize\EntrySanitizer;
use Doctrine\ORM\EntityManagerInterface;

/** Queues the posts whose embed or link cards are still to come; a post that shares links gets its bare URLs linked. */
final readonly class PostEnrichmentQueue
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
            $this->entityManager->persist(new PostEnrichment($entry, PostEnrichmentKind::AppView, $queuedAt));

            return;
        }
        $shared = self::sharedLinksOf($entry);
        if ($shared !== null) {
            $this->storeLinked($entry, $shared);
            $this->entityManager->persist(new PostEnrichment($entry, PostEnrichmentKind::LinkCards, $queuedAt));
        }
    }

    private function storeLinked(Entry $entry, SharedLinksModel $shared): void
    {
        if ($shared->linkedHtml !== $entry->getContentHtml()) {
            $entry->setContentHtml($this->sanitizer->sanitize($shared->linkedHtml));
        }
    }

    /** Null unless the entry is a post, not a Bluesky one, that shares at least one link. */
    private static function sharedLinksOf(Entry $entry): ?SharedLinksModel
    {
        if (!$entry->isTitleDerived() || AtPostUri::matches($entry->getGuid())) {
            return null;
        }
        $shared = SharedLinks::of((string) $entry->getContentHtml());

        return $shared->urls === [] ? null : $shared;
    }
}
