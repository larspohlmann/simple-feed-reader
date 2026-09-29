<?php

declare(strict_types=1);

namespace App\Service\Fetch\Support;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Url\Support\AbsoluteHttpUrl;
use App\Service\Url\Support\UrlOrigin;

/**
 * Resolves a reference against the URL it was found in — a Location header
 * against the URL that produced it, a page's own links against the page.
 */
final class UrlResolver
{
    public static function resolve(string $baseUrl, string $location): string
    {
        if (AbsoluteHttpUrl::matches($location)) {
            return $location;
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new FeedUnreachableException(sprintf('Cannot resolve redirect target "%s"', $location));
        }

        $origin = UrlOrigin::fromParts($parts['scheme'], $parts['host'], $parts['port'] ?? null);

        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $segments = explode('/', $parts['path'] ?? '/');
        array_pop($segments);

        return $origin . implode('/', $segments) . '/' . $location;
    }

    private function __construct()
    {
    }
}
