<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\LinkCard\Support\LinkPost;
use App\Service\LinkCard\Support\SharedLinks;

/**
 * Flags the rows still being completed: a Bluesky post waiting for the AppView, or a post sharing a link whose cards
 * were never tried. Runs after LinkCardLoader, whose cards it reads; one query and one cache read per page.
 */
final readonly class EntryPendingLoader
{
    public function __construct(
        private PendingPostEnrichmentRepository $pendingPosts,
        private TriedPostsInterface $triedPosts,
    ) {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function loadInto(array $rows): array
    {
        $pendingIds = [
            ...$this->pendingPosts->queuedEntryIdsAmong(EntryListRow::entryIdsOf($rows)),
            ...$this->untriedLinkPostIds($rows),
        ];

        return array_map(static fn (EntryListRow $row): EntryListRow => self::flag($row, $pendingIds), $rows);
    }

    /** @param list<int> $pendingIds */
    private static function flag(EntryListRow $row, array $pendingIds): EntryListRow
    {
        return $row
            ->withDuplicates(array_map(
                static fn (EntryListRow $duplicate): EntryListRow => self::flag($duplicate, $pendingIds),
                $row->duplicates,
            ))
            ->withPending(\in_array($row->entry->getId(), $pendingIds, true));
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<int>
     */
    private function untriedLinkPostIds(array $rows): array
    {
        $awaitingIds = [];
        foreach ($rows as $row) {
            foreach ([$row, ...$row->duplicates] as $member) {
                if (self::awaitsCards($member)) {
                    $awaitingIds[] = $member->entry->requireId();
                }
            }
        }

        return array_values(array_diff($awaitingIds, $this->triedPosts->triedAmong($awaitingIds)));
    }

    private static function awaitsCards(EntryListRow $row): bool
    {
        return $row->linkCards === []
            && LinkPost::matches($row->entry)
            && SharedLinks::in((string) $row->entry->getContentHtml()) !== [];
    }
}
