<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListRow;
use App\Repository\EntryListSort;
use App\Service\Curation\Trending\Model\FollowedStoryEntriesModel;

/** A followed story's page of entries. */
final readonly class FollowedStoryPage
{
    public function __construct(
        private EntryPage $entryPage,
    ) {
    }

    /**
     * @return array{
     *   entries: list<array<string, mixed>>,
     *   nextCursor: string|null,
     *   lastOpenedAt: string,
     *   newEntryIds: list<int>,
     * }
     */
    public function of(FollowedStoryEntriesModel $result, int $limit): array
    {
        $page = $this->entryPage->of($result->rows, $limit, EntryListSort::PublishedDate);
        $page['entries'] = array_map(
            static fn (array $entry, EntryListRow $row): array => $entry + [
                'joinedAt' => $result->joinedAtByEntry[$row->entry->requireId()]->format(\DateTimeInterface::ATOM),
            ],
            $page['entries'],
            $result->rows,
        );

        return $page + [
            'lastOpenedAt' => $result->lastOpenedAt->format(\DateTimeInterface::ATOM),
            'newEntryIds' => $result->newEntryIds(),
        ];
    }
}
