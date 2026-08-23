<?php

declare(strict_types=1);

namespace App\Service\Url;

/**
 * The one rule for accepting an image URL a feed supplied, shared by everything
 * that stores one: entry images, and a feed's own logo.
 *
 * A URL is rejected in the two ways it can be unusable, rather than repaired:
 *
 * - Scheme: the reader SPA is served over https, so an http:// image is
 *   mixed-content-blocked and never renders — dead weight, silently. A
 *   `//host/path` protocol-relative URL is unambiguous and upgraded; a `data:`
 *   URI or a site-relative path has no scheme to upgrade and no base URL is
 *   plumbed this deep to resolve one against, so it is dropped rather than
 *   guessed at. The same check keeps a `javascript:` value out of the DOM.
 * - Length: a URL over the column's limit is NOT truncated. Cutting it at
 *   exactly that many characters does not shorten a valid URL, it produces a
 *   different, broken one that will 404 in the reader.
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
}
