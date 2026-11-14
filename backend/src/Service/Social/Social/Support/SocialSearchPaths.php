<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

final class SocialSearchPaths
{
    private const int BLUESKY_PAGE_SIZE = 100;
    private const int MASTODON_PAGE_SIZE = 40;
    private const int LEMMY_PAGE_SIZE = 50;

    public static function bluesky(string $query): string
    {
        return '/xrpc/app.bsky.feed.searchPosts?q=' . rawurlencode($query)
            . '&sort=latest&limit=' . self::BLUESKY_PAGE_SIZE;
    }

    public static function mastodon(string $query, ?string $newestSeenId): string
    {
        return '/api/v2/search?type=statuses&resolve=false&limit=' . self::MASTODON_PAGE_SIZE
            . '&q=' . rawurlencode($query)
            . (null === $newestSeenId ? '' : '&min_id=' . rawurlencode($newestSeenId));
    }

    public static function lemmy(string $query): string
    {
        return '/api/v3/search?q=' . rawurlencode($query)
            . '&type_=Posts&sort=New&listing_type=All&limit=' . self::LEMMY_PAGE_SIZE;
    }

    private function __construct()
    {
    }
}
