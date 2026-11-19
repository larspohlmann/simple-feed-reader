<?php

declare(strict_types=1);

namespace App\Service\Foundation\Url\Support;

use App\Service\Foundation\Url\Pass\TrackedUrl;

final class TrackingFreeUrl
{
    public static function stripTracking(string $url): string
    {
        return TrackedUrl::fromUrl($url)->cleaned();
    }

    public static function orNull(?string $url): ?string
    {
        return $url === null ? null : self::stripTracking($url);
    }

    /** The cleaned URL and what it lost, from one read of the URL. */
    public static function read(string $url): TrackedUrl
    {
        return TrackedUrl::fromUrl($url);
    }

    public static function query(string $query, string $host): string
    {
        return TrackedUrl::inQuery($query, $host)->keptQuery();
    }

    private function __construct()
    {
    }
}
