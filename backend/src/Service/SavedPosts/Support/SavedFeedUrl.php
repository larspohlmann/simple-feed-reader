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
        return null !== self::partsOf($url);
    }

    public static function networkOf(string $url): ?SocialNetwork
    {
        return self::networkIn(self::partsOf($url) ?? []);
    }

    /** A restore names the restoring user's own saved feed, never the one the file came from. */
    public static function rebound(string $url, int $userId): string
    {
        $parts = self::partsOf($url);

        return null === $parts ? $url : self::of($userId, self::networkIn($parts));
    }

    /** @return array<int, string>|null */
    private static function partsOf(string $url): ?array
    {
        return 1 === preg_match(self::PATTERN, $url, $parts) ? $parts : null;
    }

    /** @param array<int, string> $parts */
    private static function networkIn(array $parts): ?SocialNetwork
    {
        return SocialNetwork::tryFrom($parts[1] ?? '');
    }

    private function __construct()
    {
    }
}
