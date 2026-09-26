<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum EntryView: string
{
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

        return self::tryFrom($value)
            ?? throw new ValidationException(['view' => ['Unknown view. Use one of: ' . self::valueList() . '.']]);
    }

    public function isChronological(): bool
    {
        return $this === self::All || $this === self::Unread;
    }

    private static function valueList(): string
    {
        return implode(', ', array_map(static fn (self $view): string => $view->value, self::cases()));
    }
}
