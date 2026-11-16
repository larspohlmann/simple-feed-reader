<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Repository\EntryListRow;

/** A page of a followed story's entries, the reader's last visit, and which rows joined since. */
final readonly class FollowedStoryEntriesModel
{
    /**
     * @param list<EntryListRow> $rows
     * @param list<int>          $newEntryIds
     */
    public function __construct(
        public array $rows,
        public \DateTimeImmutable $lastOpenedAt,
        public array $newEntryIds,
    ) {
    }

    /** @param list<EntryListRow> $rows */
    #[\NoDiscard]
    public function withRows(array $rows): self
    {
        return clone($this, ['rows' => $rows]);
    }
}
