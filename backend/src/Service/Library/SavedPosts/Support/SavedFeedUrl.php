<?php

declare(strict_types=1);

namespace App\Service\Library\SavedPosts\Support;

use App\Enum\SocialNetwork;

/** A virtual feed's address, `saved:<userId>[:<network>]`: one row per user and network, never fetched. */
final class SavedFeedUrl
{
    private const string SCHEME = 'saved:';
    private const string PATTERN = '/^saved:[1-9]\d*(?::([a-z]+))?$/';

    public static function buildUrl(int $userId, ?SocialNetwork $network): string
    {
        $url = self::SCHEME . $userId;

        return null === $network ? $url : $url . ':' . $network->value;
    }

    public static function isSaved(string $url): bool
    {
        return null !== self::parseParts($url);
    }

    public static function parseNetwork(string $url): ?SocialNetwork
    {
        return self::networkIn(self::parseParts($url) ?? []);
    }

    /** A restore names the restoring user's own saved feed, never the one the file came from. */
    public static function rebound(string $url, int $userId): string
    {
        $parts = self::parseParts($url);

        return null === $parts ? $url : self::buildUrl($userId, self::networkIn($parts));
    }

    /** @return array<int, string>|null */
    private static function parseParts(string $url): ?array
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
