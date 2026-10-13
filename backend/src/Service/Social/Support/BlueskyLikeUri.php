<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

/** The AT URI of a Bluesky like record, `at://<did>/app.bsky.feed.like/<rkey>`, as the remote id column can hold it. */
final class BlueskyLikeUri
{
    private const int MAX_LENGTH = 512;

    public static function orNull(?string $uri): ?string
    {
        if (null === $uri || \strlen($uri) > self::MAX_LENGTH) {
            return null;
        }
        $pattern = '#^at://' . BlueskyActor::DID_SYNTAX . '/app\.bsky\.feed\.like/[^/\s]+\z#';

        return 1 === preg_match($pattern, $uri) ? $uri : null;
    }

    private function __construct()
    {
    }
}
