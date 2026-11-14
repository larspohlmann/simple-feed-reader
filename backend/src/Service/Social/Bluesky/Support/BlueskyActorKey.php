<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

final class BlueskyActorKey
{
    private const string DID_PREFIX = 'did:';

    public static function isDid(string $actor): bool
    {
        return str_starts_with($actor, self::DID_PREFIX);
    }

    /** A DID as it is, a handle lower-cased: handles are case-insensitive, DIDs are not. */
    public static function of(string $actor): string
    {
        return self::isDid($actor) ? $actor : strtolower($actor);
    }

    private function __construct()
    {
    }
}
