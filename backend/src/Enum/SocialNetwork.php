<?php

declare(strict_types=1);

namespace App\Enum;

enum SocialNetwork: string
{
    case Bluesky = 'bluesky';
    case Mastodon = 'mastodon';

    public function displayName(): string
    {
        return match ($this) {
            self::Bluesky => 'Bluesky',
            self::Mastodon => 'Mastodon',
        };
    }
}
