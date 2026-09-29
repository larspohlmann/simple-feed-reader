<?php

declare(strict_types=1);

namespace App\Service\Url\Support;

/** The one definition of a URL's origin, so no caller re-assembles one and forgets the port. */
final class UrlOrigin
{
    public static function of(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return self::fromParts($parts['scheme'], $parts['host'], $parts['port'] ?? null);
    }

    public static function fromParts(string $scheme, string $host, ?int $port): string
    {
        return $scheme . '://' . $host . (null === $port ? '' : ':' . $port);
    }
}
