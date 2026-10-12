<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum EntryView: string
{
    use RequestValueParsing;

    case All = 'all';
    case Unread = 'unread';
    case Favorites = 'favorites';
    case Kept = 'kept';
    case Viewed = 'viewed';
    case ForYou = 'for-you';

    /**
     * @throws ValidationException when the value names no view
     */
    public static function fromRequestValue(?string $value): self
    {
        if ($value === null) {
            return self::All;
        }

        return self::caseOrRefusal($value, 'view');
    }

    public function isChronological(): bool
    {
        return $this === self::All || $this === self::Unread;
    }
}
