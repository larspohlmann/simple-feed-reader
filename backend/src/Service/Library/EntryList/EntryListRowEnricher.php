<?php

declare(strict_types=1);

namespace App\Service\Library\EntryList;

use App\Entity\Entry;
use App\Enum\CommentsLoad;
use App\Enum\SourceFormat;
use App\Repository\EntryCategoryRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowDiscussion;
use App\Repository\EntryListRowEnrichment;
use App\Repository\EntryListRowSocial;
use App\Repository\LinkCardRepository;
use App\Repository\SavedEntryOriginRepository;
use App\Repository\SavedSearchEntryRepository;
use App\Repository\ShareEnrichmentRepository;
use App\Repository\ThreadPostRepository;
use App\Service\Content\Comments\Support\SubstackPostAddress;

/**
 * Gives a page of rows their categories, saved searches, link cards, thread posts, pending flag, social state,
 * discussion and saved origin: one query per lookup, two for the thread posts and their cards.
 */
final readonly class EntryListRowEnricher
{
    public function __construct(
        private EntryCategoryRepository $categories,
        private SavedSearchEntryRepository $memberships,
        private LinkCardRepository $linkCards,
        private ShareEnrichmentRepository $shareEnrichments,
        private ThreadPostRepository $threadPosts,
        private SavedEntryOriginRepository $savedOrigins,
        private ConnectedAccountStates $connectedAccountStates,
    ) {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function enrich(array $rows, int $userId): array
    {
        $enrichments = $this->enrichmentsByEntryId($rows, $userId);

        return array_map(static fn (EntryListRow $row): EntryListRow => $row->enrichedFrom($enrichments), $rows);
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return array<int, EntryListRowEnrichment>
     */
    private function enrichmentsByEntryId(array $rows, int $userId): array
    {
        $entries = self::entriesById($rows);
        $entryIds = array_keys($entries);
        $categories = $this->categories->labelsByEntryIds($entryIds);
        $savedSearches = $this->memberships->savedSearchesByEntry($userId, $entryIds);
        $linkCards = $this->linkCards->contentsByEntryIds($entryIds);
        $threads = $this->threadPosts->byEntryIds($entryIds);
        $pendingIds = array_flip($this->shareEnrichments->pendingEntryIdsAmong(self::topLevelEntryIdsOf($rows)));
        $states = $this->connectedAccountStates->buildStates($entries, $userId);
        $origins = $this->savedOrigins->originsByEntryIds(self::savedEntryIdsOf($entries));

        $enrichments = [];
        foreach ($entryIds as $entryId) {
            $enrichments[$entryId] = new EntryListRowEnrichment(
                $categories[$entryId] ?? [],
                $savedSearches[$entryId] ?? [],
                $linkCards[$entryId] ?? [],
                isset($pendingIds[$entryId]),
                $states->social[$entryId] ?? null,
                self::discussionOf($entries[$entryId], $states->social[$entryId] ?? null),
                $origins[$entryId] ?? null,
                $threads[$entryId] ?? [],
                $states->lemmy[$entryId] ?? null,
            );
        }

        return $enrichments;
    }

    /**
     * @param array<int, Entry> $entries
     *
     * @return list<int>
     */
    private static function savedEntryIdsOf(array $entries): array
    {
        return array_keys(array_filter(
            $entries,
            static fn (Entry $entry): bool => SourceFormat::isSaved($entry->getFeed()->getSourceFormat()),
        ));
    }

    private static function discussionOf(Entry $entry, ?EntryListRowSocial $social): ?EntryListRowDiscussion
    {
        $isPlatformThread = null !== $social || null !== SubstackPostAddress::of($entry);

        return $isPlatformThread ? new EntryListRowDiscussion($entry->getUrl(), CommentsLoad::Auto) : null;
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return array<int, Entry> the entries of these rows and of their duplicates, each once, by id
     */
    private static function entriesById(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            foreach ([$row, ...$row->duplicates] as $member) {
                $entries[$member->entry->requireId()] = $member->entry;
            }
        }

        return $entries;
    }

    /**
     * Only a top-level row can be pending, never one of its duplicates.
     *
     * @param list<EntryListRow> $rows
     *
     * @return list<int>
     */
    private static function topLevelEntryIdsOf(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): int => $row->entry->requireId(), $rows);
    }
}
