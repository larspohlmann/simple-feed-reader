<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\SavedSearch;

final class SavedSearchJson
{
    /**
     * @param list<int> $unreadEntryIds
     *
     * @return array{
     *     id: int|null,
     *     term: string,
     *     wholeWord: bool,
     *     phrase: bool,
     *     position: int,
     *     unreadEntryIds: list<int>,
     *     includeInDigest: bool,
     * }
     */
    public static function one(SavedSearch $savedSearch, array $unreadEntryIds): array
    {
        return [
            'id' => $savedSearch->getId(),
            'term' => $savedSearch->getTerm(),
            'wholeWord' => $savedSearch->isWholeWord(),
            'phrase' => $savedSearch->isPhrase(),
            'position' => $savedSearch->getPosition(),
            'unreadEntryIds' => $unreadEntryIds,
            'includeInDigest' => $savedSearch->isIncludeInDigest(),
        ];
    }
}
