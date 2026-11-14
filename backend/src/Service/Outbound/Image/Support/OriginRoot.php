<?php

declare(strict_types=1);

namespace App\Service\Image\Support;

final class OriginRoot
{
    public static function of(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        $port = parse_url($url, \PHP_URL_PORT);

        return sprintf('%s://%s%s/', $scheme, $host, \is_int($port) ? ':' . $port : '');
    }

    private function __construct()
    {
    }
}
