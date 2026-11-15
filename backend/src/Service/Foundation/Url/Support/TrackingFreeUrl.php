<?php

declare(strict_types=1);

namespace App\Service\Foundation\Url\Support;

/** The #2237 spec names every key below and why; a gift key is never stripped, whatever else would match it. */
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
        'mrfcid', 'traffic_source', 'sara_ref', 'igshid', 'igsh', '_bhlid', 'sfmc_id', 'rb_clickid',
        'triedredirect', 'showwelcomeonshare',
    ];
    private const array SHARE_KEYS_BY_DOMAIN = [
        'youtube.com' => ['si', 'feature'],
        'youtu.be' => ['si', 'feature'],
        'spotify.com' => ['si'],
        'nytimes.com' => ['smid', 'smtyp'],
        'substack.com' => ['r'],
        'x.com' => ['s', 't'],
        'twitter.com' => ['s', 't'],
        'instagram.com' => ['exln', 'srtk', 'rpxt'],
    ];
    private const array FEED_MARKER_KEYS = ['cmp', 'via', 'ref'];
    private const array FEED_MARKERS = ['rss', 'feed', 'atom'];
    private const array GIFT_KEYS = [
        'unlocked_article_code', 'gift', 'pwapi_token', 'accesstoken', 'sharetype', 'gifttoken',
        'giftcode', 'gift_token', 'share_token', 'access_token',
    ];
    private const array GIFT_KEYS_BY_DOMAIN = ['wsj.com' => ['st']];
    private const string DOUBLE_ESCAPED_SEPARATOR = 'amp;';

    public static function of(string $url): string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || parse_url($url, \PHP_URL_SCHEME) === null) {
            return $url;
        }

        [$beforeFragment, $fragment] = self::splitAt($url, '#');
        [$address, $query] = self::splitAt($beforeFragment, '?');
        $host = strtolower($host);

        return $address . self::queryPart($query, $host) . self::fragmentPart($fragment, $host);
    }

    public static function orNull(?string $url): ?string
    {
        return $url === null ? null : self::of($url);
    }

    public static function query(string $query, string $host): string
    {
        return self::keptPairs($query, strtolower($host));
    }

    private static function keptPairs(string $query, string $host): string
    {
        return implode('&', array_filter(
            explode('&', $query),
            static fn (string $pair): bool => !self::isTracking($pair, $host),
        ));
    }

    /**
     * @param array<string, list<string>> $keysByDomain
     *
     * @return list<string> the keys listed for the host's domain
     */
    private static function keysOn(array $keysByDomain, string $host): array
    {
        foreach ($keysByDomain as $domain => $keys) {
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

    private static function queryPart(?string $query, string $host): string
    {
        if ($query === null || $query === '') {
            return $query === null ? '' : '?';
        }

        $kept = self::keptPairs($query, $host);

        return $kept === '' ? '' : '?' . $kept;
    }

    private static function fragmentPart(?string $fragment, string $host): string
    {
        if ($fragment === null || self::isTrackingFragment($fragment, $host)) {
            return '';
        }

        return '#' . $fragment;
    }

    private static function isTrackingFragment(string $fragment, string $host): bool
    {
        return str_contains($fragment, '=')
            && array_all(
                explode('&', $fragment),
                static fn (string $pair): bool => self::isTracking($pair, $host),
            );
    }

    private static function isTracking(string $pair, string $host): bool
    {
        $key = self::keyOf($pair);
        if (self::isGift($key, $host)) {
            return false;
        }

        return in_array($key, self::KEYS, true)
            || in_array($key, self::keysOn(self::SHARE_KEYS_BY_DOMAIN, $host), true)
            || array_any(self::PREFIXES, static fn (string $prefix): bool => str_starts_with($key, $prefix))
            || self::isFeedMarker($key, $pair);
    }

    private static function isGift(string $key, string $host): bool
    {
        return in_array($key, self::GIFT_KEYS, true)
            || in_array($key, self::keysOn(self::GIFT_KEYS_BY_DOMAIN, $host), true);
    }

    private static function isFeedMarker(string $key, string $pair): bool
    {
        $value = explode('=', $pair, 2)[1] ?? null;

        return $value !== null
            && in_array($key, self::FEED_MARKER_KEYS, true)
            && in_array(strtolower(rawurldecode($value)), self::FEED_MARKERS, true);
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
