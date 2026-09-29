<?php

declare(strict_types=1);

namespace App\Service\Url;

/** Keeps the path and every non-tracking parameter verbatim: two articles differing by `?id=` must never collapse. */
final readonly class UrlNormalizer
{
    /** Query keys, or key prefixes, that never identify the article itself. */
    private const array TRACKING_PREFIXES = ['utm_', 'at_', 'wt_'];
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

    /** Every writer of an article's identity hashes here: a divergence of one byte silently stops dedupe. */
    public function hash(?string $url): ?string
    {
        $normalized = $this->normalize($url);

        return $normalized === null ? null : hash('sha256', $normalized);
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
