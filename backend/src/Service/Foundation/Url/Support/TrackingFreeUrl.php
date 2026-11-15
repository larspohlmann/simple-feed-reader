<?php

declare(strict_types=1);

namespace App\Service\Foundation\Url\Support;

/** The #2237 spec names every key below and why. */
final class TrackingFreeUrl
{
    /** Never stripped: TrackingFreeUrlTest fails when any rule below matches one of them on its host. */
    public const array GIFT_KEYS = [
        'unlocked_article_code', 'gift', 'pwapi_token', 'accesstoken', 'sharetype', 'gifttoken',
        'giftcode', 'gift_token', 'share_token', 'access_token',
    ];
    public const array GIFT_KEYS_BY_DOMAIN = ['wsj.com' => ['st']];

    private const array DOCUMENTED_PREFIXES = ['utm_', 'at_', 'wt_', 'mtm_'];
    private const array DOCUMENTED_KEYS = [
        'gclid' => true, 'gclsrc' => true, 'dclid' => true, 'gbraid' => true, 'wbraid' => true, 'srsltid' => true,
        '_gl' => true, 'fbclid' => true, 'msclkid' => true, 'ttclid' => true, 'twclid' => true, 'li_fat_id' => true,
        'yclid' => true, 'mc_cid' => true, 'mc_eid' => true, 'mc_tc' => true,
        '_hsenc' => true, '_hsmi' => true, '__hstc' => true, '__hssc' => true, '__hsfp' => true,
        'mkt_tok' => true, 'adobe_mc' => true, 'vero_id' => true, 'vero_conv' => true, 'oly_enc_id' => true,
        'pk_campaign' => true, 'pk_kwd' => true, 'pk_keyword' => true, 'pk_source' => true, 'pk_medium' => true,
        'pk_content' => true, 'pk_cid' => true, 'piwik_campaign' => true, 'piwik_kwd' => true,
        'matomo_campaign' => true, 'matomo_kwd' => true, 'xtor' => true, 'echobox' => true,
    ];
    private const array UNDOCUMENTED_KEYS = [
        'mrfcid' => true, 'traffic_source' => true, 'sara_ref' => true, 'igshid' => true, 'igsh' => true,
        '_bhlid' => true, 'sfmc_id' => true, 'rb_clickid' => true, 'triedredirect' => true,
        'showwelcomeonshare' => true,
    ];
    private const array KEYS_BY_DOMAIN = [
        'youtube.com' => ['si' => true, 'feature' => true],
        'youtu.be' => ['si' => true, 'feature' => true],
        'spotify.com' => ['si' => true],
        'nytimes.com' => ['smid' => true, 'smtyp' => true],
        'substack.com' => ['r' => true],
        'x.com' => ['s' => true, 't' => true],
        'twitter.com' => ['s' => true, 't' => true],
        'instagram.com' => ['exln' => true, 'srtk' => true, 'rpxt' => true],
    ];
    private const array FEED_MARKER_KEYS = ['cmp' => true, 'via' => true, 'ref' => true];
    private const array FEED_MARKERS = ['rss' => true, 'feed' => true, 'atom' => true];
    private const string DOUBLE_ESCAPED_SEPARATOR = 'amp;';

    public static function of(string $url): string
    {
        $hostKeys = strpbrk($url, '?#') === false ? null : self::hostKeysOf($url);
        if ($hostKeys === null) {
            return $url;
        }

        [$beforeFragment, $fragment] = self::splitAt($url, '#');
        [$address, $query] = self::splitAt($beforeFragment, '?');

        return $address . self::queryPart($query, $hostKeys) . self::fragmentPart($fragment, $hostKeys);
    }

    public static function orNull(?string $url): ?string
    {
        return $url === null ? null : self::of($url);
    }

    public static function query(string $query, string $host): string
    {
        return self::keptPairs($query, self::keysOnHost(strtolower($host)));
    }

    /** @return list<string> the keys the cleaner strips from the URL, as it reads them */
    public static function strippedKeysIn(string $url): array
    {
        $hostKeys = self::hostKeysOf($url);

        return $hostKeys === null ? [] : array_map(self::keyOf(...), self::strippedPairsIn($url, $hostKeys));
    }

    /** True when the cleaner strips a key no vendor documents: a host-scoped, value-scoped or undocumented one. */
    public static function stripsAnUndocumentedKey(string $url): bool
    {
        $hostKeys = self::hostKeysOf($url);

        return $hostKeys !== null && array_any(
            self::strippedPairsIn($url, $hostKeys),
            static fn (string $pair): bool => self::isUndocumentedTracking($pair, $hostKeys),
        );
    }

    /** @return ?array<string, true> the host's own keys, or null when the URL is not absolute */
    private static function hostKeysOf(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return self::keysOnHost(strtolower($parts['host']));
    }

    /** @return array<string, true> */
    private static function keysOnHost(string $host): array
    {
        foreach (self::KEYS_BY_DOMAIN as $domain => $keys) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $keys;
            }
        }

        return [];
    }

    /**
     * @param array<string, true> $hostKeys
     *
     * @return list<string>
     */
    private static function strippedPairsIn(string $url, array $hostKeys): array
    {
        [$beforeFragment, $fragment] = self::splitAt($url, '#');
        $query = self::splitAt($beforeFragment, '?')[1] ?? '';
        $stripped = array_values(array_filter(
            explode('&', $query),
            static fn (string $pair): bool => self::isTracking($pair, $hostKeys),
        ));

        return $fragment !== null && self::isTrackingFragment($fragment, $hostKeys)
            ? [...$stripped, ...explode('&', $fragment)]
            : $stripped;
    }

    /** @param array<string, true> $hostKeys */
    private static function keptPairs(string $query, array $hostKeys): string
    {
        return implode('&', array_filter(
            explode('&', $query),
            static fn (string $pair): bool => !self::isTracking($pair, $hostKeys),
        ));
    }

    /** @return array{string, ?string} */
    private static function splitAt(string $url, string $separator): array
    {
        $position = strpos($url, $separator);

        return $position === false
            ? [$url, null]
            : [substr($url, 0, $position), substr($url, $position + 1)];
    }

    /** @param array<string, true> $hostKeys */
    private static function queryPart(?string $query, array $hostKeys): string
    {
        if ($query === null || $query === '') {
            return $query === null ? '' : '?';
        }

        $kept = self::keptPairs($query, $hostKeys);

        return $kept === '' ? '' : '?' . $kept;
    }

    /** @param array<string, true> $hostKeys */
    private static function fragmentPart(?string $fragment, array $hostKeys): string
    {
        if ($fragment === null || self::isTrackingFragment($fragment, $hostKeys)) {
            return '';
        }

        return '#' . $fragment;
    }

    /** @param array<string, true> $hostKeys */
    private static function isTrackingFragment(string $fragment, array $hostKeys): bool
    {
        return str_contains($fragment, '=')
            && array_all(
                explode('&', $fragment),
                static fn (string $pair): bool => self::isTracking($pair, $hostKeys),
            );
    }

    /** @param array<string, true> $hostKeys */
    private static function isTracking(string $pair, array $hostKeys): bool
    {
        $key = self::keyOf($pair);

        return isset(self::DOCUMENTED_KEYS[$key])
            || array_any(self::DOCUMENTED_PREFIXES, static fn (string $prefix): bool => str_starts_with($key, $prefix))
            || self::isUndocumentedTracking($pair, $hostKeys);
    }

    /** @param array<string, true> $hostKeys */
    private static function isUndocumentedTracking(string $pair, array $hostKeys): bool
    {
        $key = self::keyOf($pair);

        return isset(self::UNDOCUMENTED_KEYS[$key]) || isset($hostKeys[$key]) || self::isFeedMarker($key, $pair);
    }

    private static function isFeedMarker(string $key, string $pair): bool
    {
        $value = explode('=', $pair, 2)[1] ?? null;

        return $value !== null
            && isset(self::FEED_MARKER_KEYS[$key])
            && isset(self::FEED_MARKERS[strtolower(rawurldecode($value))]);
    }

    private static function keyOf(string $pair): string
    {
        $key = strtolower(rawurldecode(explode('=', $pair)[0]));

        return str_starts_with($key, self::DOUBLE_ESCAPED_SEPARATOR)
            ? substr($key, \strlen(self::DOUBLE_ESCAPED_SEPARATOR))
            : $key;
    }

    private function __construct()
    {
    }
}
