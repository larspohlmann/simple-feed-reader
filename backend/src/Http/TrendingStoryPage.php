<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListRow;
use App\Repository\EntryListSort;
use App\Service\Curation\Trending\Model\TrendingStoryEntriesModel;

/** A story's page of entries; `lastOpenedAt` is null for a story the reader does not follow. */
final readonly class TrendingStoryPage
{
    public function __construct(
        private EntryPage $entryPage,
    ) {
    }

    /**
     * @return array{
     *   entries: list<array<string, mixed>>,
     *   nextCursor: string|null,
     *   title: string|null,
     *   lastOpenedAt: string|null,
     *   newEntryIds: list<int>,
     * }
     */
    public function of(TrendingStoryEntriesModel $result, int $limit): array
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
            'title' => $result->title,
            'lastOpenedAt' => $result->lastOpenedAt?->format(\DateTimeInterface::ATOM),
            'newEntryIds' => $result->newEntryIds(),
        ];
    }
}
