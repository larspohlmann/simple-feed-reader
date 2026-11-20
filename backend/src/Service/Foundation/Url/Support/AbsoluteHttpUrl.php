<?php

declare(strict_types=1);

namespace App\Service\Foundation\Url\Support;

final class AbsoluteHttpUrl
{
    public static function matches(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1;
    }

    public static function orNull(?string $url): ?string
    {
        return $url !== null && self::matches($url) ? $url : null;
    }

    public static function keepIfHttps(?string $url): ?string
    {
        return null !== $url && self::matches($url) && 0 === stripos($url, 'https://') ? $url : null;
    }

    public static function orNullWithin(?string $url, int $maxLength): ?string
    {
        $absolute = self::orNull($url);

        return null !== $absolute && mb_strlen($absolute) <= $maxLength ? $absolute : null;
    }

    /** @return array{host: string, path: string}|null the host lower-cased; null for a non-http(s) URL or one without a host */
    public static function parseHostAndPath(string $url): ?array
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host']) || !self::isWebScheme($parts['scheme'] ?? '')) {
            return null;
        }

        return ['host' => strtolower($parts['host']), 'path' => $parts['path'] ?? ''];
    }

    private static function isWebScheme(string $scheme): bool
    {
        return \in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private function __construct()
    {
    }
}
