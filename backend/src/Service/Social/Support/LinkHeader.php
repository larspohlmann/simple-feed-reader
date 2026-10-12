<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Url\Support\UrlOrigin;

final class LinkHeader
{
    private const string NEXT = '/<([^>]+)>\s*;\s*rel="?next"?/';

    public static function nextOn(string $origin, ?string $header): ?string
    {
        if (null === $header || 1 !== preg_match(self::NEXT, $header, $match)) {
            return null;
        }

        return UrlOrigin::of($match[1]) === $origin ? $match[1] : null;
    }

    private function __construct()
    {
    }
}
