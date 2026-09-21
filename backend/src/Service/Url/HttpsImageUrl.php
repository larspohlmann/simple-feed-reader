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

    public static function orNull(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $absolute = str_starts_with($url, '//') ? 'https:' . $url : $url;

        if (!str_starts_with($absolute, 'https://')) {
            return null;
        }

        return mb_strlen($absolute) > self::MAX_LENGTH ? null : $absolute;
    }

    /**
     * The card-image variant of {@see orNull}: an http:// URL is rewritten to
     * https:// rather than rejected, because the same asset is very often
     * reachable over https; the background verify then confirms it.
     */
    public static function orNullUpgrading(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $upgraded = self::toHttps($url);
        if ($upgraded === null) {
            return null;
        }

        return mb_strlen($upgraded) > self::MAX_LENGTH ? null : $upgraded;
    }

    private static function toHttps(string $url): ?string
    {
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }

        return null;
    }
}
