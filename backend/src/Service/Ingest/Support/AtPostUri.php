<?php

declare(strict_types=1);

namespace App\Service\Ingest\Support;

/** A Bluesky post's AT URI, `at://<did>/app.bsky.feed.post/<rkey>`: the guid of a Bluesky feed item. */
final class AtPostUri
{
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

        return 'https://bsky.app/profile/' . $parts[1] . '/post/' . $parts[2];
    }

    private function __construct()
    {
    }
}
