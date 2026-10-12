<?php

declare(strict_types=1);

namespace App\Service\Url\Support;

/** The one definition of a URL's origin, so no caller re-assembles one and forgets the port. */
final class UrlOrigin
{
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public static function of(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return self::fromParts($parts['scheme'], $parts['host'], $parts['port'] ?? null);
    }

    /** @return string|null the origin with scheme and host lower-cased and a default port dropped */
    public static function canonical(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $host = strtolower($parts['host']);

        return self::fromParts($scheme, $host, self::isDefaultPort($scheme, $port) ? null : $port);
    }

    public static function isDefaultPort(string $scheme, ?int $port): bool
    {
        return null !== $port && $port === (self::DEFAULT_PORTS[$scheme] ?? null);
    }

    public static function fromParts(string $scheme, string $host, ?int $port): string
    {
        return $scheme . '://' . $host . (null === $port ? '' : ':' . $port);
    }

    private function __construct()
    {
    }
}
