<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\SavedSearch;

final class SavedSearchJson
{
    /**
     * @return array{id: int|null, term: string, wholeWord: bool, position: int, unreadCount: int}
     */
    public static function one(SavedSearch $savedSearch, int $unreadCount): array
    {
        return [
            'id' => $savedSearch->getId(),
            'term' => $savedSearch->getTerm(),
            'wholeWord' => $savedSearch->isWholeWord(),
            'position' => $savedSearch->getPosition(),
            'unreadCount' => $unreadCount,
        ];
    }
}
