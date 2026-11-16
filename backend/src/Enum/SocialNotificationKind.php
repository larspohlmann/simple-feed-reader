<?php

declare(strict_types=1);

namespace App\Enum;

enum SocialNotificationKind: string
{
    case Like = 'like';
    case Repost = 'repost';
    case Reply = 'reply';
    case Mention = 'mention';
    case Quote = 'quote';
    case Follow = 'follow';
    case Poll = 'poll';
}
