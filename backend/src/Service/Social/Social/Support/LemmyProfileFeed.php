<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Foundation\Url\Support\UrlOrigin;

final class LemmyProfileFeed
{
    private const string COMMUNITY_PATH = '#^/feeds/c/([^/]+)\.xml$#';
    private const string USER_MARKER = '@';
    private const string USER_PATH = '#^/feeds/u/([^/]+)\.xml$#';

    public static function buildUrl(string $community, string $host): string
    {
        return 'https://' . $host . '/feeds/c/' . $community . '.xml';
    }

    /** @return string|null a community's `name@host`, a user's `@name@host`; null when $url is no profile feed */
    public static function parseIdentity(string $url): ?string
    {
        $parts = AbsoluteHttpUrl::parseHostAndPath($url);
        if (null === $parts) {
            return null;
        }
        $host = strtolower((string) UrlOrigin::hostWithPort($url));

        if (1 === preg_match(self::COMMUNITY_PATH, $parts['path'], $match)) {
            return strtolower($match[1]) . '@' . $host;
        }

        if (1 === preg_match(self::USER_PATH, $parts['path'], $match)) {
            return self::USER_MARKER . strtolower($match[1]) . '@' . $host;
        }

        return null;
    }

    /** @return string|null the user's `name@host` when $identity names a Lemmy user; null for a community */
    public static function parseUser(string $identity): ?string
    {
        return str_starts_with($identity, self::USER_MARKER)
            ? substr($identity, \strlen(self::USER_MARKER))
            : null;
    }

    private function __construct()
    {
    }
}
