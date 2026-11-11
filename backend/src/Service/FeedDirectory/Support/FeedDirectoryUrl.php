<?php

declare(strict_types=1);

namespace App\Service\FeedDirectory\Support;

final class FeedDirectoryUrl
{
    private function __construct()
    {
    }

    public static function normalise(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false) {
            return strtolower($url);
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        if ($query === '') {
            $path = rtrim($path, '/');
        }

        return self::hostOf($parts) . $port . $path . $query;
    }

    public static function host(string $url): string
    {
        $parts = parse_url(trim($url));

        return $parts === false ? '' : self::hostOf($parts);
    }

    /** @param array<string, int|string> $parts */
    private static function hostOf(array $parts): string
    {
        $host = strtolower((string) ($parts['host'] ?? ''));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
