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
    case Search = 'search';
    case Local = 'local';
    case All = 'all';

    /**
     * The kinds that are a network's own timeline, one per account and without a remote id.
     *
     * @return list<self>
     */
    public static function timelines(): array
    {
        return [self::Home, self::Trending, self::Local, self::All];
    }

    public function followsOnePerson(): bool
    {
        return self::Author === $this;
    }

    /**
     * An allow-list, never "all but Home": a new kind stays out of sync until listed here.
     *
     * @return list<self>
     */
    public static function rememberedCollections(): array
    {
        return [self::UserList, self::CustomFeed, self::Tag];
    }

    public function isRememberedCollection(): bool
    {
        return \in_array($this, self::rememberedCollections(), true);
    }
}
