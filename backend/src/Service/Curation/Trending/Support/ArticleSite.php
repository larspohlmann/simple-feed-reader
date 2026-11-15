<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Support;

final class ArticleSite
{
    private const string LEADING_WWW = 'www.';

    public static function of(?string $url): ?string
    {
        $host = $url === null ? null : parse_url($url, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return null;
        }
        $host = mb_strtolower($host);

        return str_starts_with($host, self::LEADING_WWW) ? substr($host, \strlen(self::LEADING_WWW)) : $host;
    }

    private function __construct()
    {
    }
}
