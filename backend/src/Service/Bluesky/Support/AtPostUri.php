<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

/** A Bluesky post's AT URI, `at://<did>/app.bsky.feed.post/<rkey>`: the guid of a Bluesky feed item. */
final class AtPostUri
{
    private const string PATTERN = '#^at://([^/\s]+)/app\.bsky\.feed\.post/([^/\s]+)\z#';
    private const string WEB_PATTERN = '#^https?://(?:www\.)?bsky\.app/profile/[^/\s]+/post/[^/\s]+#i';

    public static function matches(string $uri): bool
    {
        return preg_match(self::PATTERN, $uri) === 1;
    }

    public static function webUrl(string $uri): ?string
    {
        if (preg_match(self::PATTERN, $uri, $parts) !== 1) {
            return null;
        }

        return BlueskyWeb::profileUrl($parts[1]) . '/post/' . $parts[2];
    }

    public static function isWebUrl(string $url): bool
    {
        return preg_match(self::WEB_PATTERN, $url) === 1;
    }

    private function __construct()
    {
    }
}
