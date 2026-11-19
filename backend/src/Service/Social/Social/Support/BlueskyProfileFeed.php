<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Social\Bluesky\Support\BlueskyActorKey;
use App\Service\Social\Bluesky\Support\BlueskyWeb;

final class BlueskyProfileFeed
{
    private const string PROFILE_PAGE = '#^/profile/(?<actor>[^/@]+)(?<rss>/rss)?/?$#';

    public static function buildUrl(string $did): string
    {
        return BlueskyWeb::profileUrl($did) . '/rss';
    }

    /** @return string|null the DID or lower-cased handle a profile feed follows; null when $url is none */
    public static function parseIdentity(string $url): ?string
    {
        $match = self::matchProfilePage(AbsoluteHttpUrl::parseHostAndPath($url));
        if (null === $match || '' === ($match['rss'] ?? '')) {
            return null;
        }

        return self::actorIn($match['actor']);
    }

    /** @return string|null the DID or lower-cased handle a bsky.app profile page or its feed names; null otherwise */
    public static function parseProfileActor(string $url): ?string
    {
        $match = self::matchProfilePage(AbsoluteHttpUrl::parseHostAndPath($url));
        if (null === $match) {
            return null;
        }
        $actor = self::actorIn($match['actor']);

        return BlueskyActor::isValid($actor) ? $actor : null;
    }

    /**
     * @param array{host: string, path: string}|null $parts
     *
     * @return array{actor: string, rss?: string}|null
     */
    private static function matchProfilePage(?array $parts): ?array
    {
        if (
            null === $parts
            || BlueskyWeb::HOST !== $parts['host']
            || 1 !== preg_match(self::PROFILE_PAGE, $parts['path'], $match)
        ) {
            return null;
        }

        return $match;
    }

    private static function actorIn(string $segment): string
    {
        return BlueskyActorKey::of(rawurldecode($segment));
    }

    private function __construct()
    {
    }
}
