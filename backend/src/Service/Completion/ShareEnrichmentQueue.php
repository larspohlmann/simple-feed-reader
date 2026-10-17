<?php

declare(strict_types=1);

namespace App\Service\Completion;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Enum\ShareKind;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Completion\Model\KnownSharesModel;
use App\Service\Completion\Support\PostedLinkCard;
use App\Service\Share\EntryShareClassifier;
use App\Service\Share\PendingEmbed\PendingEmbedInterface;
use App\Service\Share\PendingThread\PendingThreadInterface;
use Doctrine\ORM\EntityManagerInterface;

/** Classifies each new entry and queues the ones whose embed or link cards are still to come. */
final readonly class ShareEnrichmentQueue
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private EntryShareClassifier $classifier,
        private PendingEmbedInterface $pendingEmbed,
        private PendingThreadInterface $pendingThread,
        private KnownShareSettler $knownShareSettler,
    ) {
    }

    /** @param list<Entry> $createdEntries persisted, not yet flushed: the caller flushes */
    public function queue(array $createdEntries): void
    {
        $this->queueWithKnownShares($createdEntries, KnownSharesModel::none());
    }

    /** @param list<Entry> $createdEntries persisted, not yet flushed: the caller flushes */
    public function queueWithKnownShares(array $createdEntries, KnownSharesModel $knownShares): void
    {
        $queuedAt = $this->clock->now();
        foreach ($createdEntries as $entry) {
            $this->queueOne($entry, $knownShares, $queuedAt);
        }
    }

    private function queueOne(Entry $entry, KnownSharesModel $knownShares, \DateTimeImmutable $queuedAt): void
    {
        $post = $knownShares->blueskyPostFor($entry->getGuid());
        if ($post !== null) {
            $this->knownShareSettler->settleEmbedded($entry, $post, $queuedAt);

            return;
        }
        $this->classifier->classify($entry);
        $quote = self::postedQuoteOf($entry, $knownShares);
        if ($quote !== null) {
            $this->knownShareSettler->settleQuoted($entry, $quote);

            return;
        }
        $card = self::postedCardOf($entry, $knownShares);
        if ($card !== null) {
            $this->knownShareSettler->settleCarded($entry, $card, $queuedAt);

            return;
        }
        $this->queueCreated($entry, $queuedAt);
    }

    private static function postedQuoteOf(Entry $entry, KnownSharesModel $knownShares): ?QuotedPostContent
    {
        return $entry->getShareKind() === ShareKind::Link ? $knownShares->quotedPostFor($entry->getGuid()) : null;
    }

    private static function postedCardOf(Entry $entry, KnownSharesModel $knownShares): ?LinkCardContent
    {
        $card = $knownShares->linkCardFor($entry->getGuid());
        if ($card === null || $entry->getShareKind() !== ShareKind::Link) {
            return null;
        }

        return PostedLinkCard::of($entry, $card);
    }

    private function queueCreated(Entry $entry, \DateTimeImmutable $queuedAt): void
    {
        if ($this->pendingEmbed->awaitsEmbed($entry)) {
            $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::AppView, $queuedAt));

            return;
        }
        if ($this->pendingThread->awaitsThread($entry)) {
            $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::SelfThread, $queuedAt));

            return;
        }
        if ($entry->getShareKind() !== ShareKind::Link) {
            return;
        }
        $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::LinkCards, $queuedAt));
    }
}
