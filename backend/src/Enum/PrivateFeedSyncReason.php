<?php

declare(strict_types=1);

namespace App\Enum;

enum PrivateFeedSyncReason: string
{
    case Saved = 'saved';
    case Created = 'created';
    case Unsaved = 'unsaved';
    case Deleted = 'deleted';
    case Followed = 'followed';
    case Unfollowed = 'unfollowed';
}
