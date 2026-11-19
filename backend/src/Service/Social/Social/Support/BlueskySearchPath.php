<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

final class BlueskySearchPath
{
    private const int PAGE_SIZE = 100;

    public static function buildPath(string $query): string
    {
        return '/xrpc/app.bsky.feed.searchPosts?q=' . rawurlencode($query)
            . '&sort=latest&limit=' . self::PAGE_SIZE;
    }

    private function __construct()
    {
    }
}
