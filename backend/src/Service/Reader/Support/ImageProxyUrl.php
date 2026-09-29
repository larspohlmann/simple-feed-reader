<?php

declare(strict_types=1);

namespace App\Service\Reader\Support;

use App\Service\Url\Support\AbsoluteHttpUrl;

/**
 * Resolves the source URL an image proxy carries: a `?url=` query parameter, an imgproxy base64 path segment, or a
 * percent-encoded `/image/fetch/` segment. ImageIdentityModel fingerprints the source, so renditions match.
 */
final readonly class ImageProxyUrl
{
    /** The embedded HTTP source URL, or the original when the URL is not a proxy. */
    public static function resolve(string $url): string
    {
        $source = self::sourceFromQuery($url);
        if ($source !== null) {
            return $source;
        }

        $source = self::sourceFromPath($url);
        if ($source !== null) {
            return $source;
        }

        return self::sourceFromEncodedPath($url) ?? $url;
    }

    /** A `?url=` proxy carries the source verbatim. */
    private static function sourceFromQuery(string $url): ?string
    {
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return null;
        }

        parse_str($query, $parameters);
        $embedded = $parameters['url'] ?? null;

        return is_string($embedded) && AbsoluteHttpUrl::matches($embedded) ? $embedded : null;
    }

    /** Decode imgproxy's URL-safe base64 source in the final path segment. */
    private static function sourceFromPath(string $url): ?string
    {
        $segment = basename((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $candidate = (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', $segment);
        $decoded = base64_decode(strtr($candidate, '-_', '+/'), true);

        return $decoded !== false && AbsoluteHttpUrl::matches($decoded) ? $decoded : null;
    }

    /** A percent-encoded source as the final path segment (`/image/fetch/<transforms>/<source>`). */
    private static function sourceFromEncodedPath(string $url): ?string
    {
        $decoded = rawurldecode(basename((string) (parse_url($url, PHP_URL_PATH) ?? '')));

        return AbsoluteHttpUrl::matches($decoded) ? $decoded : null;
    }

    private function __construct()
    {
    }
}
