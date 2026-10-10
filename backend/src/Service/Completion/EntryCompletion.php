<?php

declare(strict_types=1);

namespace App\Service\Completion;

use App\Entity\Entry;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Service\Bluesky\PostEnricher;
use App\Service\LinkCard\PostLinkCards;

/** Completes the posts a client still shows as pending, without waiting for the next refresh or an open. */
final readonly class EntryCompletion
{
    public function __construct(
        private EntryListRepository $entryList,
        private EntryListRowEnricher $enricher,
        private PostEnricher $postEnricher,
        private PostLinkCards $postLinkCards,
    ) {
    }

    /**
     * Bluesky posts are filled first, as they cost one AppView call between them; link posts then share what page
     * fetches the call allows.
     *
     * @param list<int> $entryIds
     *
     * @return list<EntryListRow> the caller's rows among them, as far as this call completed them
     */
    public function complete(int $userId, array $entryIds): array
    {
        $rows = $this->rowsFor($userId, $entryIds);
        $pendingEntries = self::pendingEntriesOf($rows);
        if ($pendingEntries === []) {
            return $rows;
        }

        $this->postEnricher->enrichEntries($pendingEntries);
        $this->postLinkCards->forPending($pendingEntries);

        return $this->rowsFor($userId, $entryIds);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<EntryListRow>
     */
    private function rowsFor(int $userId, array $entryIds): array
    {
        return $this->enricher->enrich($this->entryList->rowsByIdsForUser($userId, $entryIds), $userId);
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<Entry>
     */
    private static function pendingEntriesOf(array $rows): array
    {
        $pendingEntries = [];
        foreach ($rows as $row) {
            foreach ([$row, ...$row->duplicates] as $member) {
                if ($member->pending) {
                    $pendingEntries[] = $member->entry;
                }
            }
        }

        return $pendingEntries;
    }
}
