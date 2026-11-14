<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Library\Reading\Model\UpdatedEntryStateModel;

final class EntryStateJson
{
    /**
     * @return array{
     *   entryId: int, isHidden: bool, isFavorite: bool, isKept: bool,
     *   hiddenAt: string|null, isViewed: bool, viewedAt: string|null,
     *   isShared: bool, sharedAt: string|null
     * }
     */
    public static function one(UpdatedEntryStateModel $updated, int $entryId): array
    {
        $state = $updated->state;

        return [
            'entryId' => $entryId,
            'isHidden' => $state->isHidden(),
            'isFavorite' => $state->isFavorite(),
            'isKept' => $state->isKept(),
            'hiddenAt' => $state->getHiddenAt()?->format(\DateTimeInterface::ATOM),
            'isViewed' => $state->isViewed(),
            'viewedAt' => $state->getViewedAt()?->format(\DateTimeInterface::ATOM),
            'isShared' => null !== $updated->sharedAt,
            'sharedAt' => $updated->sharedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
