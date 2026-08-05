<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;

/**
 * Resolves a reference against the URL it was found in — a Location header
 * against the URL that produced it, a page's own links against the page.
 */
final class UrlResolver
{
    /**
     * Scheme, host and port of a URL, without the trailing slash: everything a
     * relative reference keeps from its base. Null when the string names no
     * host — the one definition of that, so callers stop re-assembling an
     * origin (and forgetting the port) for themselves.
     */
    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return self::originOf($parts['scheme'], $parts['host'], $parts['port'] ?? null);
    }

    public static function resolve(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new FeedUnreachableException(sprintf('Cannot resolve redirect target "%s"', $location));
        }

        $origin = self::originOf($parts['scheme'], $parts['host'], $parts['port'] ?? null);

        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . ($directory === '' ? '/' : $directory) . $location;
    }

    private static function originOf(string $scheme, string $host, ?int $port): string
    {
        return $scheme . '://' . $host . (null === $port ? '' : ':' . $port);
    }
}
