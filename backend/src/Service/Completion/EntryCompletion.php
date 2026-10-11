<?php

declare(strict_types=1);

namespace App\Service\Completion;

use App\Entity\Entry;
use App\Entity\PostEnrichment;
use App\Enum\PostEnrichmentKind;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\PostEnrichmentRepository;
use App\Service\Bluesky\PostEnricher;
use App\Service\Clock\NaiveUtcClock;
use App\Service\LinkCard\PostLinkCards;

final readonly class EntryCompletion
{
    public function __construct(
        private EntryListRepository $entryList,
        private EntryListRowEnricher $enricher,
        private PostEnrichmentRepository $enrichments,
        private PostEnricher $postEnricher,
        private PostLinkCards $postLinkCards,
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
     * Bluesky posts go first, as one AppView call fills them all; link posts then share the call's page fetches.
     *
     * @param list<Entry> $entries
     */
    private function completeQueued(array $entries): void
    {
        $queued = $this->enrichments->findClaimableFor($entries, $this->clock->now());
        $this->postEnricher->complete(self::ofKind($queued, PostEnrichmentKind::AppView));
        $this->postLinkCards->complete(self::ofKind($queued, PostEnrichmentKind::LinkCards));
    }

    /**
     * @param list<PostEnrichment> $queued
     *
     * @return list<PostEnrichment>
     */
    private static function ofKind(array $queued, PostEnrichmentKind $kind): array
    {
        return array_values(array_filter($queued, static fn (PostEnrichment $row): bool => $row->getKind() === $kind));
    }
}
