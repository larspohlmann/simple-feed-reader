<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Url\Support\UrlOrigin;

final class LinkHeader
{
    private const string NEXT = '/<([^>]+)>\s*;\s*rel="?next"?/';

    public static function next(?string $header): ?string
    {
        return null !== $header && 1 === preg_match(self::NEXT, $header, $match) ? $match[1] : null;
    }

    public static function isOn(string $origin, string $url): bool
    {
        $page = UrlOrigin::canonical($url);

        return null !== $page && UrlOrigin::canonical($origin) === $page;
    }

    private function __construct()
    {
    }
}
