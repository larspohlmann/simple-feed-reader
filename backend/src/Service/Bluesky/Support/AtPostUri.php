<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

/** A Bluesky post's AT URI, `at://<did>/app.bsky.feed.post/<rkey>`: the guid of a Bluesky feed item. */
final class AtPostUri
{
    private const string PROFILE_URL = 'https://bsky.app/profile/';
    private const string PATTERN = '#^at://([^/\s]+)/app\.bsky\.feed\.post/([^/\s]+)\z#';

    public static function matches(string $uri): bool
    {
        return preg_match(self::PATTERN, $uri) === 1;
    }

    public static function webUrl(string $uri): ?string
    {
        if (preg_match(self::PATTERN, $uri, $parts) !== 1) {
            return null;
        }

        return self::profileUrl($parts[1]) . '/post/' . $parts[2];
    }

    /** A Bluesky profile's web page; the actor is a DID or a handle. */
    public static function profileUrl(string $actor): string
    {
        return self::PROFILE_URL . $actor;
    }

    private function __construct()
    {
    }
}
