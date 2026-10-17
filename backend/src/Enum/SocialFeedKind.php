<?php

declare(strict_types=1);

namespace App\Enum;

enum SocialFeedKind: string
{
    case Home = 'home';
    case UserList = 'list';
    case CustomFeed = 'custom_feed';
    case Author = 'author';

    public function followsOnePerson(): bool
    {
        return self::Author === $this;
    }
}
