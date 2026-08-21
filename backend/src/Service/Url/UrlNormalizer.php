<?php

declare(strict_types=1);

namespace App\Service\Url;

/**
 * Reduces an article URL to a stable canonical form so two links that point at
 * the same article dedupe to one identity, even when a feed decorates the URL
 * with per-fetch tracking parameters (BBC's `?at_medium=RSS&at_campaign=rss`)
 * or a fragment.
 *
 * Deliberately conservative: it lowercases only the scheme and host, drops the
 * default port and the fragment, and removes a known set of tracking
 * parameters. The path and every other query parameter are kept verbatim, so
 * two genuinely different articles that share a base URL but differ by a real
 * query parameter (`?id=42` vs `?id=43`) never collapse into one.
 */
final class UrlNormalizer
{
    /** Query keys, or key prefixes, that never identify the article itself. */
    private const array TRACKING_PREFIXES = ['utm_', 'at_'];
    private const array TRACKING_KEYS = ['fbclid', 'gclid'];
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function normalize(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $canonical = $scheme . '://' . strtolower($parts['host'])
            . $this->port($scheme, $parts['port'] ?? null)
            . ($parts['path'] ?? '')
            . $this->query($parts['query'] ?? null);

        return $canonical;
    }

    private function port(string $scheme, ?int $port): string
    {
        if ($port === null || $port === (self::DEFAULT_PORTS[$scheme] ?? null)) {
            return '';
        }

        return ':' . $port;
    }

    private function query(?string $query): string
    {
        if ($query === null || $query === '') {
            return '';
        }

        $kept = array_filter(
            explode('&', $query),
            fn (string $pair): bool => !$this->isTracking($pair),
        );

        return $kept === [] ? '' : '?' . implode('&', $kept);
    }

    private function isTracking(string $pair): bool
    {
        $key = strtolower(explode('=', $pair, 2)[0]);

        if (in_array($key, self::TRACKING_KEYS, true)) {
            return true;
        }

        foreach (self::TRACKING_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
