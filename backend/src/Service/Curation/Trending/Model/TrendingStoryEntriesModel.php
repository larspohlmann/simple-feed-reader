<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Repository\EntryListRow;

/** A page of a story's entries, its title and synopsis, its tally, its sources and shares, the reader's last visit if they follow it, when each row joined, and its lifespan. */
final readonly class TrendingStoryEntriesModel
{
    /**
     * @param list<EntryListRow>             $rows
     * @param array<int, \DateTimeImmutable> $joinedAtByEntry one for every row
     */
    public function __construct(
        public int $storyId,
        public array $rows,
        public ?StoryHeadingModel $heading,
        public TrendingStoryTallyModel $tally,
        public StorySourcesModel $sources,
        public ?\DateTimeImmutable $lastOpenedAt,
        public array $joinedAtByEntry,
        public StoryLifespanModel $lifespan,
    ) {
    }

    public function face(): ?StoryFaceModel
    {
        if ($this->heading === null) {
            return null;
        }

        return new StoryFaceModel(
            $this->storyId,
            $this->heading,
            $this->sources,
            $this->lastOpenedAt !== null,
            $this->lifespan,
        );
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
