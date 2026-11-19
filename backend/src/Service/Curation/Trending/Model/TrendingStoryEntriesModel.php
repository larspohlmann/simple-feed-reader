<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Repository\EntryListRow;

/** A page of a story's entries, its title and synopsis, its tally, the reader's last visit if they follow it, and when each row joined. */
final readonly class TrendingStoryEntriesModel
{
    /**
     * @param list<EntryListRow>             $rows
     * @param array<int, \DateTimeImmutable> $joinedAtByEntry one for every row
     */
    public function __construct(
        public array $rows,
        public ?StoryHeadingModel $heading,
        public TrendingStoryTallyModel $tally,
        public ?\DateTimeImmutable $lastOpenedAt,
        public array $joinedAtByEntry,
    ) {
    }

    /** @return list<int> the rows that joined since the last visit, in page order; none for a story not followed */
    public function newEntryIds(): array
    {
        if ($this->lastOpenedAt === null) {
            return [];
        }
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
