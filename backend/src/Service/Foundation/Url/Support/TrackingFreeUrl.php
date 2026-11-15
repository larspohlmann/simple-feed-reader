<?php

declare(strict_types=1);

namespace App\Service\Foundation\Url\Support;

/** Every key and prefix below is named in the #2237 spec's two source tables: vendor keys and per-host share keys. */
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
    private const array SHARE_KEYS_BY_DOMAIN = [
        'youtube.com' => ['si', 'feature'],
        'youtu.be' => ['si', 'feature'],
        'spotify.com' => ['si'],
        'nytimes.com' => ['smid', 'smtyp'],
    ];
    private const string DOUBLE_ESCAPED_SEPARATOR = 'amp;';

    public static function of(string $url): string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || parse_url($url, \PHP_URL_SCHEME) === null) {
            return $url;
        }

        [$beforeFragment, $fragment] = self::splitAt($url, '#');
        [$address, $query] = self::splitAt($beforeFragment, '?');
        $shareKeys = self::shareKeysOn(strtolower($host));

        return $address . self::queryPart($query, $shareKeys) . self::fragmentPart($fragment, $shareKeys);
    }

    public static function orNull(?string $url): ?string
    {
        return $url === null ? null : self::of($url);
    }

    public static function query(string $query, string $host): string
    {
        return self::keptPairs($query, self::shareKeysOn(strtolower($host)));
    }

    /** @param list<string> $shareKeys */
    private static function keptPairs(string $query, array $shareKeys): string
    {
        return implode('&', array_filter(
            explode('&', $query),
            static fn (string $pair): bool => !self::isTracking($pair, $shareKeys),
        ));
    }

    /** @return list<string> the keys the host appends to its own share links, which name no page */
    private static function shareKeysOn(string $host): array
    {
        foreach (self::SHARE_KEYS_BY_DOMAIN as $domain => $keys) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $keys;
            }
        }

        return [];
    }

    /** @return array{string, ?string} */
    private static function splitAt(string $url, string $separator): array
    {
        $position = strpos($url, $separator);

        return $position === false
            ? [$url, null]
            : [substr($url, 0, $position), substr($url, $position + 1)];
    }

    /** @param list<string> $shareKeys */
    private static function queryPart(?string $query, array $shareKeys): string
    {
        if ($query === null || $query === '') {
            return $query === null ? '' : '?';
        }

        $kept = self::keptPairs($query, $shareKeys);

        return $kept === '' ? '' : '?' . $kept;
    }

    /** @param list<string> $shareKeys */
    private static function fragmentPart(?string $fragment, array $shareKeys): string
    {
        if ($fragment === null || self::isTrackingFragment($fragment, $shareKeys)) {
            return '';
        }

        return '#' . $fragment;
    }

    /** @param list<string> $shareKeys */
    private static function isTrackingFragment(string $fragment, array $shareKeys): bool
    {
        return str_contains($fragment, '=')
            && array_all(
                explode('&', $fragment),
                static fn (string $pair): bool => self::isTracking($pair, $shareKeys),
            );
    }

    /** @param list<string> $shareKeys */
    private static function isTracking(string $pair, array $shareKeys): bool
    {
        $key = self::keyOf($pair);

        return in_array($key, self::KEYS, true)
            || in_array($key, $shareKeys, true)
            || array_any(self::PREFIXES, static fn (string $prefix): bool => str_starts_with($key, $prefix));
    }

    private static function keyOf(string $pair): string
    {
        $key = strtolower(rawurldecode(explode('=', $pair)[0]));

        return str_starts_with($key, self::DOUBLE_ESCAPED_SEPARATOR)
            ? substr($key, strlen(self::DOUBLE_ESCAPED_SEPARATOR))
            : $key;
    }

    private function __construct()
    {
    }
}
