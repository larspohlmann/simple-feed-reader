<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Support\BlueskyActorKey;
use App\Service\Social\Bluesky\Support\BlueskyWeb;

final class ProfileFeedUrl
{
    private const string BLUESKY_PROFILE_PAGE = '#^/profile/(?<actor>[^/@]+)(?<rss>/rss)?/?$#';
    private const string MASTODON_PATH = '#^/@([^/@]+)\.rss$#';
    private const string LEMMY_COMMUNITY_PATH = '#^/feeds/c/([^/]+)\.xml$#';

    public static function bluesky(string $did): string
    {
        return BlueskyWeb::profileUrl($did) . '/rss';
    }

    public static function mastodon(string $user, string $domain): string
    {
        return 'https://' . $domain . '/@' . $user . '.rss';
    }

    public static function lemmy(string $community, string $host): string
    {
        return 'https://' . $host . '/feeds/c/' . $community . '.xml';
    }

    /** @return string|null the profile feed of $identity on $network; null when $identity names no account there */
    public static function of(SocialNetwork $network, string $identity): ?string
    {
        $url = match ($network) {
            SocialNetwork::Bluesky => self::bluesky($identity),
            SocialNetwork::Mastodon => self::accountOf($identity, self::mastodon(...)),
            SocialNetwork::Lemmy => self::accountOf($identity, self::lemmy(...)),
        };
        $builtIdentity = null === $url ? null : self::identityOf($network, $url);

        return null !== $builtIdentity && 0 === strcasecmp($builtIdentity, $identity) ? $url : null;
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
        $actor = self::actorIn($match['actor']);

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
            SocialNetwork::Lemmy => self::lemmyIdentity($host, $path),
        };
    }

    /** @param callable(string, string): string $feedOf */
    private static function accountOf(string $identity, callable $feedOf): ?string
    {
        $parts = explode('@', $identity);

        return 2 === \count($parts) ? $feedOf($parts[0], $parts[1]) : null;
    }

    private static function isWeb(string $scheme): bool
    {
        return \in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private static function blueskyIdentity(string $host, string $path): ?string
    {
        if (
            BlueskyWeb::HOST !== $host
            || 1 !== preg_match(self::BLUESKY_PROFILE_PAGE, $path, $match)
            || '' === ($match['rss'] ?? '')
        ) {
            return null;
        }

        return self::actorIn($match['actor']);
    }

    private static function actorIn(string $segment): string
    {
        return BlueskyActorKey::of(rawurldecode($segment));
    }

    private static function mastodonIdentity(string $host, string $path): ?string
    {
        return 1 === preg_match(self::MASTODON_PATH, $path, $match) ? $match[1] . '@' . $host : null;
    }

    private static function lemmyIdentity(string $host, string $path): ?string
    {
        return 1 === preg_match(self::LEMMY_COMMUNITY_PATH, $path, $match) ? strtolower($match[1]) . '@' . $host : null;
    }

    private function __construct()
    {
    }
}
