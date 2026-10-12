<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\BlueskyWeb;

final class ProfileFeedUrl
{
    private const string BLUESKY_PATH = '#^/profile/([^/]+)/rss/?$#';
    private const string MASTODON_PATH = '#^/@([^/@]+)\.rss$#';
    private const string DID_PREFIX = 'did:';

    public static function bluesky(string $did): string
    {
        return BlueskyWeb::profileUrl($did) . '/rss';
    }

    public static function mastodon(string $user, string $domain): string
    {
        return 'https://' . $domain . '/@' . $user . '.rss';
    }

    public static function isDid(string $identity): bool
    {
        return str_starts_with($identity, self::DID_PREFIX);
    }

    /** @return string|null the DID, handle or user@host the URL follows; null when it is no profile feed of $network */
    public static function identityOf(SocialNetwork $network, string $url): ?string
    {
        $parts = self::webPartsOf($url);

        return null === $parts ? null : self::identityIn($network, $parts['host'], $parts['path']);
    }

    /** @return SocialNetwork|null the network whose profile feed $url is; null when it is no profile feed */
    public static function networkOf(string $url): ?SocialNetwork
    {
        $parts = self::webPartsOf($url);
        if (null === $parts) {
            return null;
        }
        foreach (SocialNetwork::cases() as $network) {
            if (null !== self::identityIn($network, $parts['host'], $parts['path'])) {
                return $network;
            }
        }

        return null;
    }

    /** @return array{host: string, path: string}|null */
    private static function webPartsOf(string $url): ?array
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host']) || !self::isWeb($parts['scheme'] ?? '')) {
            return null;
        }

        return ['host' => strtolower($parts['host']), 'path' => $parts['path'] ?? ''];
    }

    private static function identityIn(SocialNetwork $network, string $host, string $path): ?string
    {
        return match ($network) {
            SocialNetwork::Bluesky => self::blueskyIdentity($host, $path),
            SocialNetwork::Mastodon => self::mastodonIdentity($host, $path),
        };
    }

    private static function isWeb(string $scheme): bool
    {
        return \in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private static function blueskyIdentity(string $host, string $path): ?string
    {
        if (BlueskyWeb::HOST !== $host || 1 !== preg_match(self::BLUESKY_PATH, $path, $match)) {
            return null;
        }
        $actor = rawurldecode($match[1]);

        return self::isDid($actor) ? $actor : strtolower($actor);
    }

    private static function mastodonIdentity(string $host, string $path): ?string
    {
        return 1 === preg_match(self::MASTODON_PATH, $path, $match) ? $match[1] . '@' . $host : null;
    }

    private function __construct()
    {
    }
}
