<?php

declare(strict_types=1);

namespace App\Service\Url\Support;

/**
 * Accepts a feed-supplied image URL only as https (a //host URL is upgraded) and within MAX_LENGTH, never repairing
 * one: http is mixed-content-blocked, a truncated URL is a different broken one, and the scheme check keeps
 * `javascript:` out of the DOM.
 */
final class HttpsImageUrl
{
    /** Matches the length of every column one of these is persisted into. */
    public const int MAX_LENGTH = 2048;

    /** A //host URL is not native: its https is as unproven as an upgraded http one. */
    public static function isNativeHttps(string $url): bool
    {
        return self::withoutPrefix($url, 'https://') !== null;
    }

    public static function orNull(?string $url): ?string
    {
        return self::withinColumn(self::secure($url ?? ''));
    }

    /**
     * The card-image variant of {@see orNull}: an http:// URL is rewritten to
     * https:// rather than rejected, because the same asset is very often
     * reachable over https; the background verify then confirms it.
     */
    public static function orNullUpgrading(?string $url): ?string
    {
        return self::withinColumn(self::secure($url ?? '') ?? self::upgraded($url ?? ''));
    }

    private static function secure(string $url): ?string
    {
        $rest = self::withoutPrefix($url, 'https://') ?? self::withoutPrefix($url, '//');

        return $rest === null ? null : 'https://' . $rest;
    }

    private static function upgraded(string $url): ?string
    {
        $rest = self::withoutPrefix($url, 'http://');

        return $rest === null ? null : 'https://' . $rest;
    }

    private static function withoutPrefix(string $url, string $prefix): ?string
    {
        return strncasecmp($url, $prefix, \strlen($prefix)) === 0 ? substr($url, \strlen($prefix)) : null;
    }

    private static function withinColumn(?string $url): ?string
    {
        return $url === null || mb_strlen($url) > self::MAX_LENGTH ? null : $url;
    }

    private function __construct()
    {
    }
}
