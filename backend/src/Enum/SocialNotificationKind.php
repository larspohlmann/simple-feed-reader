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

    public function groups(): bool
    {
        return match ($this) {
            self::Like, self::Repost, self::Follow, self::Poll => true,
            self::Reply, self::Mention, self::Quote => false,
        };
    }
}
