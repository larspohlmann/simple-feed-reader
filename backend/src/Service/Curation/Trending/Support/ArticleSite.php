<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Support;

use App\Service\Outbound\Fetch\Support\HostKey;

final class ArticleSite
{
    /** The URL's host as HostKey keys it, or null when the URL has none. */
    public static function fromUrl(?string $url): ?string
    {
        return $url === null ? null : HostKey::tryForUrl($url);
    }

    private function __construct()
    {
    }
}
