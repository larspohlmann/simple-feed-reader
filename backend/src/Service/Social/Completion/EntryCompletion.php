<?php

declare(strict_types=1);

namespace App\Service\Completion;

use App\Entity\Entry;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\ShareEnrichmentRepository;
use App\Service\Bluesky\PostEnricher;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Completion\SelfThreadPass\SelfThreadPassInterface;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\LinkCard\ShareLinkCards;

final readonly class EntryCompletion
{
    private const int LINK_CARD_FETCHES = 1;

    public function __construct(
        private EntryListRepository $entryList,
        private EntryListRowEnricher $enricher,
        private ShareEnrichmentRepository $enrichments,
        private PostEnricher $postEnricher,
        private SelfThreadPassInterface $selfThreads,
        private ShareLinkCards $shareLinkCards,
        private NaiveUtcClock $clock,
    ) {
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<EntryListRow> the caller's rows among them, as far as this call completed them
     */
    public function complete(int $userId, array $entryIds): array
    {
        $rows = $this->entryList->rowsByIdsForUser($userId, $entryIds);
        $this->completeQueued(array_map(static fn (EntryListRow $row): Entry => $row->entry, $rows));

        return $this->enricher->enrich($rows, $userId);
    }

    /**
     * Bluesky posts go first, as one AppView call fills them all; self-reply threads follow, and link posts last, so a
     * root a fold hands on gets its cards in the same completion. Each step re-queries what the one before handed on.
     *
     * @param list<Entry> $entries
     */
    private function completeQueued(array $entries): void
    {
        $queued = $this->enrichments->findClaimableFor($entries, $this->clock->now());
        if ($queued === []) {
            return;
        }
        $this->postEnricher->complete(self::ofKind($queued, ShareEnrichmentKind::AppView));
        $this->selfThreads->complete($this->queuedOfKind($entries, ShareEnrichmentKind::SelfThread));
        $this->shareLinkCards->complete(
            $this->queuedOfKind($entries, ShareEnrichmentKind::LinkCards),
            LinkCardBudgetModel::fetches(self::LINK_CARD_FETCHES),
        );
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<ShareEnrichment>
     */
    private function queuedOfKind(array $entries, ShareEnrichmentKind $kind): array
    {
        return self::ofKind($this->enrichments->findClaimableFor($entries, $this->clock->now()), $kind);
    }

    /**
     * @param list<ShareEnrichment> $queued
     *
     * @return list<ShareEnrichment>
     */
    private static function ofKind(array $queued, ShareEnrichmentKind $kind): array
    {
        return array_values(array_filter($queued, static fn (ShareEnrichment $row): bool => $row->getKind() === $kind));
    }
}
