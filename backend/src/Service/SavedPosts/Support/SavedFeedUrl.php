<?php

declare(strict_types=1);

namespace App\Service\SavedPosts\Support;

use App\Enum\SocialNetwork;

/** A virtual feed's address, `saved:<userId>[:<network>]`: one row per user and network, never fetched. */
final class SavedFeedUrl
{
    private const string SCHEME = 'saved:';
    private const string PATTERN = '/^saved:[1-9]\d*(?::([a-z]+))?$/';

    public static function of(int $userId, ?SocialNetwork $network): string
    {
        $url = self::SCHEME . $userId;

        return null === $network ? $url : $url . ':' . $network->value;
    }

    public static function isSaved(string $url): bool
    {
        return 1 === preg_match(self::PATTERN, $url);
    }

    public static function networkOf(string $url): ?SocialNetwork
    {
        if (1 !== preg_match(self::PATTERN, $url, $parts)) {
            return null;
        }

        return SocialNetwork::tryFrom($parts[1] ?? '');
    }

    /** A restore names the restoring user's own saved feed, never the one the file came from. */
    public static function rebound(string $url, int $userId): string
    {
        return self::isSaved($url) ? self::of($userId, self::networkOf($url)) : $url;
    }

    private function __construct()
    {
    }
}
