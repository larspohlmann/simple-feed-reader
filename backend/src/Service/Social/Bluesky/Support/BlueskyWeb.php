<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky\Support;

final class BlueskyWeb
{
    public const string HOST = 'bsky.app';
    public const string URL = 'https://' . self::HOST;

    /** The actor is a DID or a handle. */
    public static function profileUrl(string $actor): string
    {
        return self::URL . '/profile/' . $actor;
    }

    public static function searchUrl(string $query): string
    {
        return self::URL . '/search?q=' . rawurlencode($query);
    }

    private function __construct()
    {
    }
}
