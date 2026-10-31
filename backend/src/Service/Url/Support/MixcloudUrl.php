<?php

declare(strict_types=1);

namespace App\Service\Url\Support;

final class MixcloudUrl
{
    private const array PAGE_HOSTS = ['www.mixcloud.com', 'mixcloud.com', 'm.mixcloud.com'];

    private const string API_HOST = 'api.mixcloud.com';

    /** A key segment as the API spells it: ASCII, with any other character percent-encoded. */
    private const string SEGMENT = '(?:[A-Za-z0-9_-]|%[0-9A-Fa-f]{2})+';

    /** First path segments that are the site's own sections, never a user. */
    private const array SITE_SECTIONS = [
        'about', 'categories', 'dashboard', 'developers', 'discover', 'genres', 'help', 'live', 'messages',
        'notifications', 'oembed', 'player', 'pro', 'search', 'select', 'settings', 'tag', 'upload', 'widget',
    ];

    /** Second path segments that are a profile's own tabs, never a show. */
    private const array PROFILE_TABS = [
        'favorites', 'followers', 'following', 'hosts', 'listens', 'playlists', 'reposts', 'stream', 'uploads',
    ];

    public static function profileUser(string $url): ?string
    {
        $path = self::pagePath($url);
        if ($path === null || preg_match('#^/(' . self::SEGMENT . ')/?\z#', $path, $matches) !== 1) {
            return null;
        }

        return self::isSiteSection($matches[1]) ? null : $matches[1];
    }

    public static function showKey(string $url): ?string
    {
        $path = self::pagePath($url);
        if ($path === null) {
            return null;
        }
        $key = rtrim($path, '/') . '/';

        return self::isShowKey($key) ? $key : null;
    }

    public static function isShowKey(string $key): bool
    {
        if (preg_match('#^/(' . self::SEGMENT . ')/(' . self::SEGMENT . ')/\z#', $key, $matches) !== 1) {
            return false;
        }

        return !self::isSiteSection($matches[1]) && !\in_array(strtolower($matches[2]), self::PROFILE_TABS, true);
    }

    public static function cloudcastsUrl(string $user): string
    {
        return 'https://' . self::API_HOST . '/' . $user . '/cloudcasts/';
    }

    public static function isCloudcastsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || strtolower($parts['host'] ?? '') !== self::API_HOST) {
            return false;
        }

        return preg_match('#^/' . self::SEGMENT . '/cloudcasts/?\z#', $parts['path'] ?? '') === 1;
    }

    private static function pagePath(string $url): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !\in_array(strtolower($parts['host'] ?? ''), self::PAGE_HOSTS, true)) {
            return null;
        }

        return $parts['path'] ?? '/';
    }

    private static function isSiteSection(string $segment): bool
    {
        return \in_array(strtolower($segment), self::SITE_SECTIONS, true);
    }

    private function __construct()
    {
    }
}
