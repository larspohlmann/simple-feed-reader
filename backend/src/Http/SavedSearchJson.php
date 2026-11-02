<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\SavedSearch;
use App\Service\Search\Model\SavedSearchTallyModel;

final class SavedSearchJson
{
    /**
     * @return array{
     *     id: int|null,
     *     slug: string|null,
     *     term: string,
     *     wholeWord: bool,
     *     phrase: bool,
     *     position: int,
     *     unreadEntryIds: list<int>,
     *     memberCount: int,
     *     includeInDigest: bool,
     * }
     */
    public static function one(SavedSearch $savedSearch, SavedSearchTallyModel $tally): array
    {
        return [
            'id' => $savedSearch->getId(),
            'slug' => $savedSearch->getSlug(),
            'term' => $savedSearch->getTerm(),
            'wholeWord' => $savedSearch->isWholeWord(),
            'phrase' => $savedSearch->isPhrase(),
            'position' => $savedSearch->getPosition(),
            'unreadEntryIds' => $tally->unreadEntryIds,
            'memberCount' => $tally->memberCount,
            'includeInDigest' => $savedSearch->isIncludeInDigest(),
        ];
    }
}
