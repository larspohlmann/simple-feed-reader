<?php

declare(strict_types=1);

namespace App\Enum;

enum SocialFeedKind: string
{
    case Home = 'home';
    case UserList = 'list';
    case CustomFeed = 'custom_feed';
    case Author = 'author';
    case Tag = 'tag';
    case Trending = 'trending';

    public function followsOnePerson(): bool
    {
        return self::Author === $this;
    }

    public function isRememberedCollection(): bool
    {
        return self::UserList === $this || self::CustomFeed === $this || self::Tag === $this;
    }
}
