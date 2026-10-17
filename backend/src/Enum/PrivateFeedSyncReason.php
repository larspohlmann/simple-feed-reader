<?php

declare(strict_types=1);

namespace App\Enum;

enum PrivateFeedSyncReason: string
{
    case Saved = 'saved';
    case Created = 'created';
    case Unsaved = 'unsaved';
    case Deleted = 'deleted';

    public function isAddition(): bool
    {
        return match ($this) {
            self::Saved, self::Created => true,
            self::Unsaved, self::Deleted => false,
        };
    }
}
