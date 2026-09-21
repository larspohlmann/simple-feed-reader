<?php

declare(strict_types=1);

namespace App\Service\Url;

/**
 * The one rule for accepting an image URL a feed supplied, shared by
 * everything that stores one: entry images, and a feed's own logo.
 *
 * Rejected, never repaired, in the two ways it can be unusable:
 *
 * - Scheme: the reader SPA is served over https, so http:// is
 *   mixed-content-blocked — dead weight, silently. A `//host/path`
 *   protocol-relative URL is unambiguous and upgraded; a `data:` URI or a
 *   site-relative path has no scheme to upgrade and no base URL is plumbed
 *   this deep to resolve one against, so it is dropped. The same check keeps
 *   `javascript:` out of the DOM.
 * - Length: a URL over the column's limit is NOT truncated — cutting it at
 *   that many characters produces a different, broken URL that 404s in the
 *   reader, not a shortened valid one.
 *
 * {@see orNullUpgrading} is the card-image variant: it upgrades http:// to
 * https:// instead of rejecting it.
 */
final class HttpsImageUrl
{
    /** Matches the length of every column one of these is persisted into. */
    public const int MAX_LENGTH = 2048;

    /** A //host URL is not native: its https is as unproven as an upgraded http one. */
    public static function isNativeHttps(string $url): bool
    {
        return self::withoutPrefix($url, 'https://') !== null;
    }

    public static function orNull(?string $url): ?string
    {
        return self::withinColumn(self::secure($url ?? ''));
    }

    /**
     * The card-image variant of {@see orNull}: an http:// URL is rewritten to
     * https:// rather than rejected, because the same asset is very often
     * reachable over https; the background verify then confirms it.
     */
    public static function orNullUpgrading(?string $url): ?string
    {
        return self::withinColumn(self::secure($url ?? '') ?? self::upgraded($url ?? ''));
    }

    private static function secure(string $url): ?string
    {
        $rest = self::withoutPrefix($url, 'https://') ?? self::withoutPrefix($url, '//');

        return $rest === null ? null : 'https://' . $rest;
    }

    private static function upgraded(string $url): ?string
    {
        $rest = self::withoutPrefix($url, 'http://');

        return $rest === null ? null : 'https://' . $rest;
    }

    private static function withoutPrefix(string $url, string $prefix): ?string
    {
        return strncasecmp($url, $prefix, \strlen($prefix)) === 0 ? substr($url, \strlen($prefix)) : null;
    }

    private static function withinColumn(?string $url): ?string
    {
        return $url === null || mb_strlen($url) > self::MAX_LENGTH ? null : $url;
    }
}
