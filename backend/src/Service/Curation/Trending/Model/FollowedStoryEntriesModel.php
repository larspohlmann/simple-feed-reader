<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Repository\EntryListRow;

/** A page of a followed story's entries, the reader's last visit, and when each row joined the story. */
final readonly class FollowedStoryEntriesModel
{
    /**
     * @param list<EntryListRow>               $rows
     * @param array<int, \DateTimeImmutable> $joinedAtByEntry one for every row
     */
    public function __construct(
        public array $rows,
        public \DateTimeImmutable $lastOpenedAt,
        public array $joinedAtByEntry,
    ) {
    }

    /** @return list<int> the rows that joined since the last visit, in page order */
    public function newEntryIds(): array
    {
        $newEntryIds = [];
        foreach ($this->rows as $row) {
            $entryId = $row->entry->requireId();
            if ($this->joinedAtByEntry[$entryId] > $this->lastOpenedAt) {
                $newEntryIds[] = $entryId;
            }
        }

        return $newEntryIds;
    }

    /** @param list<EntryListRow> $rows */
    #[\NoDiscard]
    public function withRows(array $rows): self
    {
        return clone($this, ['rows' => $rows]);
    }
}
