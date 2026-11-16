<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\EntryListSort;
use App\Service\Curation\Trending\Model\FollowedStoryEntriesModel;

/** The `{entries, nextCursor, lastOpenedAt, newEntryIds}` shape of a followed story's entry list. */
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
        return $this->entryPage->of($result->rows, $limit, EntryListSort::PublishedDate) + [
            'lastOpenedAt' => $result->lastOpenedAt->format(\DateTimeInterface::ATOM),
            'newEntryIds' => $result->newEntryIds,
        ];
    }
}
