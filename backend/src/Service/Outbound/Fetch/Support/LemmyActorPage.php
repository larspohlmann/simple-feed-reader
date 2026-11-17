<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Support;

/**
 * For FaviconResolver: the HTML page of the Lemmy community or user a feed URL belongs to, where lemmy-ui embeds the
 * actor's own icon.
 */
final class LemmyActorPage
{
    private const string ACTOR_PATH = '#^/(?:feeds/)?(?<kind>[cu])/(?<name>[^/]+?)(?:\.xml|/)?$#';

    public static function of(string $url): ?string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        $path = parse_url($url, \PHP_URL_PATH);
        if (!\is_string($host) || '' === $host || !\is_string($path)) {
            return null;
        }
        if (1 !== preg_match(self::ACTOR_PATH, $path, $matches)) {
            return null;
        }

        return 'https://' . $host . '/' . $matches['kind'] . '/' . $matches['name'];
    }

    private function __construct()
    {
    }
}
