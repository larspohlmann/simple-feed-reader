<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Support;

use App\Service\Outbound\Fetch\Support\HostKey;

final class ArticleSite
{
    /** The URL's host as HostKey keys it, or null when the URL has none. */
    public static function of(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return \is_string($host) && $host !== '' ? HostKey::forUrl($url) : null;
    }

    private function __construct()
    {
    }
}
