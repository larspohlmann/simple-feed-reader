<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Url\Support\UrlOrigin;

final class LinkHeader
{
    private const string NEXT = '/<([^>]+)>\s*;\s*rel="?next"?/';
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public static function next(?string $header): ?string
    {
        return null !== $header && 1 === preg_match(self::NEXT, $header, $match) ? $match[1] : null;
    }

    public static function isOn(string $origin, string $url): bool
    {
        $page = self::originOf($url);

        return null !== $page && self::originOf($origin) === $page;
    }

    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;

        return UrlOrigin::fromParts(
            $scheme,
            strtolower($parts['host']),
            $port === (self::DEFAULT_PORTS[$scheme] ?? null) ? null : $port,
        );
    }

    private function __construct()
    {
    }
}
