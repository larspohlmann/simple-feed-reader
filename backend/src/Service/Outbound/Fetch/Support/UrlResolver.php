<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Support;

use App\Service\Outbound\Fetch\Exception\FeedUnreachableException;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use Uri\WhatWg\Url;

/**
 * Resolves a reference against the URL it was found in — a Location header
 * against the URL that produced it, a page's own links against the page.
 * An absolute http(s) reference comes back verbatim, so stored URLs keep their identity.
 */
final class UrlResolver
{
    private const array HTTP_SCHEMES = ['http', 'https'];

    public static function resolve(string $baseUrl, string $location): string
    {
        if (AbsoluteHttpUrl::matches($location)) {
            return $location;
        }

        $base = Url::parse($baseUrl);
        $resolved = $base?->getAsciiHost() === null ? null : Url::parse($location, $base);
        if ($resolved === null || !\in_array($resolved->getScheme(), self::HTTP_SCHEMES, true)) {
            throw new FeedUnreachableException(sprintf('Cannot resolve redirect target "%s"', $location));
        }

        return $resolved->toAsciiString();
    }

    private function __construct()
    {
    }
}
