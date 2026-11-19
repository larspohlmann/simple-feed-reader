<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;

final class MastodonProfileFeed
{
    private const string PROFILE_FEED_PATH = '#^/@([^/@]+)\.rss$#';

    public static function buildUrl(string $user, string $domain): string
    {
        return 'https://' . $domain . '/@' . $user . '.rss';
    }

    /** @return string|null the user@host a profile feed follows; null when $url is none */
    public static function parseIdentity(string $url): ?string
    {
        $parts = AbsoluteHttpUrl::parseHostAndPath($url);
        if (null === $parts || 1 !== preg_match(self::PROFILE_FEED_PATH, $parts['path'], $match)) {
            return null;
        }

        return $match[1] . '@' . $parts['host'];
    }

    private function __construct()
    {
    }
}
