<?php

declare(strict_types=1);

namespace App\Service\Foundation\Url\Pass;

/** One URL split once and read against its host's tracking rules: what the cleaner keeps, and what it strips. */
final readonly class TrackedUrl
{
    private const string DOCUMENTED_PREFIX = 'utm_';
    private const array DOCUMENTED_KEYS = [
        'gclid' => true, 'gclsrc' => true, 'dclid' => true, 'gbraid' => true, 'wbraid' => true, 'srsltid' => true,
        '_gl' => true, 'fbclid' => true, 'msclkid' => true, 'ttclid' => true, 'twclid' => true, 'li_fat_id' => true,
        'yclid' => true, 'mc_cid' => true, 'mc_eid' => true, 'mc_tc' => true,
        '_hsenc' => true, '_hsmi' => true, '__hstc' => true, '__hssc' => true, '__hsfp' => true,
        'adobe_mc' => true, 'vero_id' => true, 'vero_conv' => true,
        'at_medium' => true, 'at_campaign' => true, 'at_platform' => true, 'at_creation' => true,
        'at_variant' => true, 'at_network' => true, 'at_term' => true, 'wt_mc' => true, 'wt_cc1' => true,
        'mtm_campaign' => true, 'mtm_source' => true, 'mtm_medium' => true, 'mtm_keyword' => true, 'mtm_kwd' => true,
        'mtm_content' => true, 'mtm_cid' => true, 'mtm_group' => true, 'mtm_placement' => true,
        'pk_campaign' => true, 'pk_kwd' => true, 'pk_keyword' => true, 'pk_source' => true, 'pk_medium' => true,
        'pk_content' => true, 'pk_cid' => true, 'piwik_campaign' => true, 'piwik_kwd' => true,
        'matomo_campaign' => true, 'matomo_kwd' => true, 'xtor' => true, 'echobox' => true,
    ];
    private const array UNDOCUMENTED_KEYS = [
        'mrfcid' => true, 'sara_ref' => true, 'igshid' => true, 'igsh' => true,
        '_bhlid' => true, 'sfmc_id' => true, 'rb_clickid' => true, 'triedredirect' => true,
        'showwelcomeonshare' => true,
    ];
    private const array KEYS_BY_DOMAIN = [
        'youtube.com' => ['si' => true, 'feature' => true],
        'youtu.be' => ['si' => true, 'feature' => true],
        'spotify.com' => ['si' => true],
        'nytimes.com' => ['smid' => true],
        'substack.com' => ['r' => true],
        'x.com' => ['s' => true, 't' => true],
        'twitter.com' => ['s' => true, 't' => true],
        'instagram.com' => ['exln' => true, 'srtk' => true, 'rpxt' => true],
    ];
    private const array FEED_MARKER_KEYS = ['cmp' => true, 'via' => true];
    private const array FEED_MARKERS = ['rss' => true, 'feed' => true, 'atom' => true];
    private const string DOUBLE_ESCAPED_SEPARATOR = 'amp;';

    /** @var list<array{pair: string, key: string}> */
    private array $queryPairs;
    /** @var list<array{pair: string, key: string}> */
    private array $fragmentPairs;

    /** @param array<string, true> $hostKeys */
    private function __construct(
        private string $address,
        private ?string $query,
        private ?string $fragment,
        private array $hostKeys,
    ) {
        $this->queryPairs = self::splitPairs($query);
        $this->fragmentPairs = self::splitPairs($fragment);
    }

    public static function fromUrl(string $url): self
    {
        $host = strpbrk($url, '?#') === false ? null : self::parseAbsoluteHost($url);
        if ($host === null) {
            return new self($url, null, null, []);
        }

        [$beforeFragment, $fragment] = self::splitAt($url, '#');
        [$address, $query] = self::splitAt($beforeFragment, '?');

        return new self($address, $query, $fragment, self::keysOnHost($host));
    }

    public static function inQuery(string $query, string $host): self
    {
        return new self('', $query, null, self::keysOnHost(strtolower($host)));
    }

    public function cleaned(): string
    {
        return $this->address . $this->queryPart() . $this->fragmentPart();
    }

    /** The query's kept pairs, without a leading `?`. */
    public function keptQuery(): string
    {
        return implode('&', array_column(
            array_filter($this->queryPairs, fn (array $pair): bool => !$this->isTracking($pair)),
            'pair',
        ));
    }

    /** @return list<string> the keys the cleaner strips, as it reads them */
    public function strippedKeys(): array
    {
        return array_column($this->strippedPairs(), 'key');
    }

    /** True when a stripped key is one no vendor documents: a host-scoped, value-scoped or undocumented one. */
    public function strippedAnUndocumentedKey(): bool
    {
        return array_any($this->strippedPairs(), $this->isUndocumentedTracking(...));
    }

    private function queryPart(): string
    {
        if ($this->query === null) {
            return '';
        }
        if ($this->query === '') {
            return '?';
        }
        $kept = $this->keptQuery();

        return $kept === '' ? '' : '?' . $kept;
    }

    private function fragmentPart(): string
    {
        if ($this->fragment === null || $this->isTrackingFragment()) {
            return '';
        }

        return '#' . $this->fragment;
    }

    /** @return list<array{pair: string, key: string}> */
    private function strippedPairs(): array
    {
        $stripped = array_values(array_filter($this->queryPairs, $this->isTracking(...)));

        return $this->isTrackingFragment() ? [...$stripped, ...$this->fragmentPairs] : $stripped;
    }

    private function isTrackingFragment(): bool
    {
        return $this->fragment !== null
            && str_contains($this->fragment, '=')
            && array_all($this->fragmentPairs, $this->isTracking(...));
    }

    /** @param array{pair: string, key: string} $pair */
    private function isTracking(array $pair): bool
    {
        return isset(self::DOCUMENTED_KEYS[$pair['key']])
            || str_starts_with($pair['key'], self::DOCUMENTED_PREFIX)
            || $this->isUndocumentedTracking($pair);
    }

    /** @param array{pair: string, key: string} $pair */
    private function isUndocumentedTracking(array $pair): bool
    {
        return isset(self::UNDOCUMENTED_KEYS[$pair['key']])
            || isset($this->hostKeys[$pair['key']])
            || self::isFeedMarker($pair);
    }

    /** @param array{pair: string, key: string} $pair */
    private static function isFeedMarker(array $pair): bool
    {
        $value = explode('=', $pair['pair'], 2)[1] ?? null;

        return $value !== null
            && isset(self::FEED_MARKER_KEYS[$pair['key']])
            && isset(self::FEED_MARKERS[strtolower(rawurldecode($value))]);
    }

    private static function parseAbsoluteHost(string $url): ?string
    {
        $parts = parse_url($url);

        return $parts === false || !isset($parts['scheme'], $parts['host']) ? null : strtolower($parts['host']);
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

    /** @return list<array{pair: string, key: string}> */
    private static function splitPairs(?string $part): array
    {
        return $part === null ? [] : array_map(
            static fn (string $pair): array => ['pair' => $pair, 'key' => self::extractKey($pair)],
            explode('&', $part),
        );
    }

    private static function extractKey(string $pair): string
    {
        $key = strtolower(rawurldecode(explode('=', $pair)[0]));

        return str_starts_with($key, self::DOUBLE_ESCAPED_SEPARATOR)
            ? substr($key, \strlen(self::DOUBLE_ESCAPED_SEPARATOR))
            : $key;
    }

    /** @return array{string, ?string} */
    private static function splitAt(string $url, string $separator): array
    {
        $position = strpos($url, $separator);

        return $position === false
            ? [$url, null]
            : [substr($url, 0, $position), substr($url, $position + 1)];
    }
}
