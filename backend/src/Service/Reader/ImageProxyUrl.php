<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * Resolves the real image URL behind an image proxy or CDN wrapper. Publishers
 * route photos through a proxy that carries the true source URL in a query
 * parameter (Politico's dims4, NPR's brightspot), as a base64 path segment
 * (imgproxy) or as a percent-encoded one (Substack's `/image/fetch/`).
 * ImageIdentity fingerprints the resolved source, so two renditions behind the
 * same proxy compare as the one photo they are.
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

    /** A `?url=` proxy (Politico's dims4, NPR's brightspot) carries the source verbatim. */
    private static function sourceFromQuery(string $url): ?string
    {
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return null;
        }

        parse_str($query, $parameters);
        $embedded = $parameters['url'] ?? null;

        return is_string($embedded) && self::isHttpUrl($embedded) ? $embedded : null;
    }

    /** Decode imgproxy's URL-safe base64 source in the final path segment. */
    private static function sourceFromPath(string $url): ?string
    {
        $segment = basename((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $candidate = (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', $segment);
        $decoded = base64_decode(strtr($candidate, '-_', '+/'), true);

        return $decoded !== false && self::isHttpUrl($decoded) ? $decoded : null;
    }

    /** A percent-encoded source as the final path segment (Substack's `/image/fetch/<transforms>/<source>`). */
    private static function sourceFromEncodedPath(string $url): ?string
    {
        $decoded = rawurldecode(basename((string) (parse_url($url, PHP_URL_PATH) ?? '')));

        return self::isHttpUrl($decoded) ? $decoded : null;
    }

    private static function isHttpUrl(string $value): bool
    {
        return preg_match('#^https?://#i', $value) === 1;
    }
}
