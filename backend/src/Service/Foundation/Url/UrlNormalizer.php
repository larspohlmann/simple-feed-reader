<?php

declare(strict_types=1);

namespace App\Service\Foundation\Url;

use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use App\Service\Foundation\Url\Support\UrlOrigin;

/** Keeps the path and every non-tracking parameter verbatim: two articles differing by `?id=` must never collapse. */
final readonly class UrlNormalizer
{
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

    /** The address as a reader shares it: tracking parameters drop, a navigation fragment stays. */
    public function withoutTracking(?string $url): ?string
    {
        if ($url === null || $this->normalize($url) === null) {
            return null;
        }

        return TrackingFreeUrl::of($url);
    }

    /** Every writer of an article's identity hashes here: a divergence of one byte silently stops dedupe. */
    public function hash(?string $url): ?string
    {
        $normalized = $this->normalize($url);

        return $normalized === null ? null : hash('sha256', $normalized);
    }

    private function port(string $scheme, ?int $port): string
    {
        if ($port === null || UrlOrigin::isDefaultPort($scheme, $port)) {
            return '';
        }

        return ':' . $port;
    }

    private function query(?string $query): string
    {
        if ($query === null || $query === '') {
            return '';
        }

        $kept = TrackingFreeUrl::query($query);

        return $kept === '' ? '' : '?' . $kept;
    }
}
