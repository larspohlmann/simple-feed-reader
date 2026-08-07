<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryState;

final class EntryStateJson
{
    /**
     * @return array{
     *   entryId: int, isRead: bool, isFavorite: bool, isKept: bool,
     *   readAt: string|null, isViewed: bool, viewedAt: string|null
     * }
     */
    public static function one(EntryState $state, int $entryId): array
    {
        return [
            'entryId' => $entryId,
            'isRead' => $state->isRead(),
            'isFavorite' => $state->isFavorite(),
            'isKept' => $state->isKept(),
            'readAt' => $state->getReadAt()?->format(\DateTimeInterface::ATOM),
            'isViewed' => $state->isViewed(),
            'viewedAt' => $state->getViewedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
