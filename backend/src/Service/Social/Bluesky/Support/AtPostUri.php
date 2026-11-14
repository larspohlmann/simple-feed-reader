<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

/** A Bluesky post's AT URI, `at://<did>/app.bsky.feed.post/<rkey>`: the guid of a Bluesky feed item. */
final class AtPostUri
{
    private const string PATTERN = '#^at://([^/\s]+)/app\.bsky\.feed\.post/([^/\s]+)\z#';
    private const string WEB_PATTERN = '#^https?://(?:www\.)?bsky\.app/profile/[^/\s]+/post/[^/\s]+#i';
    private const string WEB_POST_PATTERN = '~^https?://(?:www\.)?bsky\.app/profile/([^/?#\s]+)/post/([^/?#\s]+)'
        . '/?(?:[?#]\S*)?\z~i';

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

    public static function authorityOf(string $uri): ?string
    {
        return preg_match(self::PATTERN, $uri, $parts) === 1 ? $parts[1] : null;
    }

    public static function isWebUrl(string $url): bool
    {
        return preg_match(self::WEB_PATTERN, $url) === 1;
    }

    /** The post a bsky.app address shows, named by the authority the address names: a handle or a DID. */
    public static function ofWebUrl(string $url): ?string
    {
        return preg_match(self::WEB_POST_PATTERN, $url, $parts) === 1 ? self::of($parts[1], $parts[2]) : null;
    }

    public static function withAuthority(string $uri, string $authority): string
    {
        return preg_match(self::PATTERN, $uri, $parts) === 1 ? self::of($authority, $parts[2]) : $uri;
    }

    private static function of(string $authority, string $recordKey): string
    {
        return 'at://' . $authority . '/app.bsky.feed.post/' . $recordKey;
    }

    private function __construct()
    {
    }
}
