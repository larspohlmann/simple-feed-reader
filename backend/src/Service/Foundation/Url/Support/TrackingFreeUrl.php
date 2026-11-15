<?php

declare(strict_types=1);

namespace App\Service\Foundation\Url\Support;

/** Every key and prefix below is named by its vendor's own page, listed in the #2237 spec's source table. */
final class TrackingFreeUrl
{
    private const array PREFIXES = ['utm_', 'at_', 'wt_', 'mtm_'];
    private const array KEYS = [
        'gclid', 'gclsrc', 'dclid', 'gbraid', 'wbraid', 'srsltid', '_gl',
        'fbclid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id', 'yclid',
        'mc_cid', 'mc_eid', 'mc_tc',
        '_hsenc', '_hsmi', '__hstc', '__hssc', '__hsfp',
        'mkt_tok', 'adobe_mc', 'vero_id', 'vero_conv', 'oly_enc_id',
        'pk_campaign', 'pk_kwd', 'pk_keyword', 'pk_source', 'pk_medium', 'pk_content', 'pk_cid',
        'piwik_campaign', 'piwik_kwd', 'matomo_campaign', 'matomo_kwd',
        'xtor', 'echobox',
    ];
    private const string DOUBLE_ESCAPED_SEPARATOR = 'amp;';

    public static function of(string $url): string
    {
        if (!self::isAbsolute($url)) {
            return $url;
        }

        [$beforeFragment, $fragment] = self::splitAt($url, '#');
        [$address, $query] = self::splitAt($beforeFragment, '?');

        return $address . self::queryPart($query) . self::fragmentPart($fragment);
    }

    public static function query(string $query): string
    {
        return implode('&', array_filter(
            explode('&', $query),
            static fn (string $pair): bool => !self::isTracking($pair),
        ));
    }

    private static function isAbsolute(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false && isset($parts['scheme'], $parts['host']);
    }

    /** @return array{string, ?string} */
    private static function splitAt(string $url, string $separator): array
    {
        $position = strpos($url, $separator);

        return $position === false
            ? [$url, null]
            : [substr($url, 0, $position), substr($url, $position + 1)];
    }

    private static function queryPart(?string $query): string
    {
        if ($query === null || $query === '') {
            return $query === null ? '' : '?';
        }

        $kept = self::query($query);

        return $kept === '' ? '' : '?' . $kept;
    }

    private static function fragmentPart(?string $fragment): string
    {
        if ($fragment === null || self::isTrackingFragment($fragment)) {
            return '';
        }

        return '#' . $fragment;
    }

    private static function isTrackingFragment(string $fragment): bool
    {
        return str_contains($fragment, '=')
            && array_all(explode('&', $fragment), static fn (string $pair): bool => self::isTracking($pair));
    }

    private static function isTracking(string $pair): bool
    {
        $key = self::keyOf($pair);

        return in_array($key, self::KEYS, true)
            || array_any(self::PREFIXES, static fn (string $prefix): bool => str_starts_with($key, $prefix));
    }

    private static function keyOf(string $pair): string
    {
        $key = strtolower(rawurldecode(explode('=', $pair, 2)[0]));

        return str_starts_with($key, self::DOUBLE_ESCAPED_SEPARATOR)
            ? substr($key, strlen(self::DOUBLE_ESCAPED_SEPARATOR))
            : $key;
    }

    private function __construct()
    {
    }
}
