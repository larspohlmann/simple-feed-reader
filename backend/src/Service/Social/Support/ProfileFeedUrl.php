<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\BlueskyActorKey;
use App\Service\Bluesky\Support\BlueskyWeb;

final class ProfileFeedUrl
{
    private const string BLUESKY_PATH = '#^/profile/([^/@]+)/rss/?$#';
    private const string BLUESKY_PROFILE_PAGE = '#^/profile/([^/@]+)(?:/rss)?/?$#';
    private const string MASTODON_PATH = '#^/@([^/@]+)\.rss$#';

    public static function bluesky(string $did): string
    {
        return BlueskyWeb::profileUrl($did) . '/rss';
    }

    public static function mastodon(string $user, string $domain): string
    {
        return 'https://' . $domain . '/@' . $user . '.rss';
    }

    /** @return string|null the profile feed of $identity on $network; null when $identity names no account there */
    public static function of(SocialNetwork $network, string $identity): ?string
    {
        $url = match ($network) {
            SocialNetwork::Bluesky => self::bluesky($identity),
            SocialNetwork::Mastodon => self::mastodonOf($identity),
        };
        $builtIdentity = null === $url ? null : self::identityOf($network, $url);

        return null !== $builtIdentity && 0 === strcasecmp($builtIdentity, $identity) ? $url : null;
    }

    public static function isDid(string $identity): bool
    {
        return BlueskyActorKey::isDid($identity);
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

    /** @return string|null the DID or lower-cased handle a bsky.app profile page or its feed names; null otherwise */
    public static function blueskyProfileActorOf(string $url): ?string
    {
        $parts = self::webPartsOf($url);
        if (
            null === $parts
            || BlueskyWeb::HOST !== $parts['host']
            || 1 !== preg_match(self::BLUESKY_PROFILE_PAGE, $parts['path'], $match)
        ) {
            return null;
        }
        $actor = self::actorIn($match[1]);

        return BlueskyActor::isValid($actor) ? $actor : null;
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

    private static function mastodonOf(string $identity): ?string
    {
        $parts = explode('@', $identity);

        return 2 === \count($parts) ? self::mastodon($parts[0], $parts[1]) : null;
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

        return self::actorIn($match[1]);
    }

    private static function actorIn(string $segment): string
    {
        return BlueskyActorKey::of(rawurldecode($segment));
    }

    private static function mastodonIdentity(string $host, string $path): ?string
    {
        return 1 === preg_match(self::MASTODON_PATH, $path, $match) ? $match[1] . '@' . $host : null;
    }

    private function __construct()
    {
    }
}
