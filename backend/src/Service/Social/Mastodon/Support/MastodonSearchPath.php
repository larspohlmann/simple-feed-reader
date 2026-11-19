<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

final class MastodonSearchPath
{
    private const int PAGE_SIZE = 40;

    public static function buildPath(string $query, ?string $newestSeenId): string
    {
        return '/api/v2/search?type=statuses&resolve=false&limit=' . self::PAGE_SIZE
            . '&q=' . rawurlencode($query)
            . (null === $newestSeenId ? '' : '&min_id=' . rawurlencode($newestSeenId));
    }

    private function __construct()
    {
    }
}
