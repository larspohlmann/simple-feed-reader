<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

final class BlueskyWeb
{
    public const string HOST = 'bsky.app';
    public const string URL = 'https://' . self::HOST;

    /** The actor is a DID or a handle. */
    public static function profileUrl(string $actor): string
    {
        return self::URL . '/profile/' . $actor;
    }

    private function __construct()
    {
    }
}
