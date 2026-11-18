<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\Support;

use App\Entity\PostCommunity;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Foundation\Url\Support\UrlOrigin;

final class LemmyCommunityLink
{
    private const string COMMUNITY_PATH = '#^/c/([^/@]+)(?:@([^/@]+))?/?$#';

    /** @return PostCommunity|null null unless the link is an https community page */
    public static function of(string $href): ?PostCommunity
    {
        $httpsHref = AbsoluteHttpUrl::httpsOrNull($href);
        $host = null === $httpsHref ? null : UrlOrigin::hostWithPort($httpsHref);
        $path = null === $httpsHref ? '' : (string) parse_url($httpsHref, \PHP_URL_PATH);
        if (null === $host || preg_match(self::COMMUNITY_PATH, $path, $matches) !== 1) {
            return null;
        }
        $name = strtolower($matches[1]);
        $home = strtolower($matches[2] ?? $host);

        return PostCommunity::of($name . '@' . $home, 'https://' . $home . '/c/' . $name);
    }

    private function __construct()
    {
    }
}
