<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryState;

final class EntryStateJson
{
    /**
     * @return array{
     *   entryId: int, isHidden: bool, isFavorite: bool, isKept: bool,
     *   hiddenAt: string|null, isViewed: bool, viewedAt: string|null
     * }
     */
    public static function one(EntryState $state, int $entryId): array
    {
        return [
            'entryId' => $entryId,
            'isHidden' => $state->isHidden(),
            'isFavorite' => $state->isFavorite(),
            'isKept' => $state->isKept(),
            'hiddenAt' => $state->getHiddenAt()?->format(\DateTimeInterface::ATOM),
            'isViewed' => $state->isViewed(),
            'viewedAt' => $state->getViewedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
