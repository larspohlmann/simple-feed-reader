<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Url\Support;

use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use App\Service\Foundation\Url\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrackingFreeUrlTest extends TestCase
{
    private const array DOCUMENTED_KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'utm_souce',
        'gclid', 'gclsrc', 'dclid', 'gbraid', 'wbraid', 'srsltid', '_gl',
        'fbclid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id', 'yclid',
        'mc_cid', 'mc_eid', 'mc_tc',
        '_hsenc', '_hsmi', '__hstc', '__hssc', '__hsfp',
        'mkt_tok', 'adobe_mc', 'vero_id', 'vero_conv', 'oly_enc_id',
        'mtm_campaign', 'mtm_keyword', 'pk_campaign', 'pk_kwd', 'pk_keyword', 'pk_source', 'pk_medium',
        'pk_content', 'pk_cid', 'piwik_campaign', 'piwik_kwd', 'matomo_campaign', 'matomo_kwd',
        'at_medium', 'at_campaign', 'xtor', 'wt_mc', 'wt_cc1', 'echobox',
    ];

    /** @return iterable<string, array{string}> */
    public static function documentedKeys(): iterable
    {
        foreach (self::DOCUMENTED_KEYS as $key) {
            yield $key => [$key];
        }
        yield 'upper case' => ['UTM_Source'];
        yield 'percent-encoded' => ['utm%5Fsource'];
        yield 'mixed-case vendor key' => ['FbClId'];
    }

    #[DataProvider('documentedKeys')]
    public function testEveryDocumentedKeyIsStripped(string $key): void
    {
        self::assertSame('https://example.com/a', TrackingFreeUrl::of('https://example.com/a?' . $key . '=1'));
    }

    /** @return iterable<string, array{string}> */
    public static function contentUrls(): iterable
    {
        yield 'an id' => ['https://example.com/a?id=42'];
        yield 'a post number' => ['https://example.com/?p=7'];
        yield 'a video' => ['https://www.youtube.com/watch?v=abc'];
        yield 'a time' => ['https://youtu.be/abc?t=90'];
        yield 'a playlist' => ['https://www.youtube.com/watch?v=abc&list=PL1'];
        yield 'a page' => ['https://example.com/a?page=2'];
        yield 'a gift link' => ['https://www.nytimes.com/2026/a.html?unlocked_article_code=x'];
        yield 'a gift token' => ['https://example.com/a?gift=y'];
        yield 'a key that only starts like a vendor key' => ['https://example.com/a?utmost=1&atom=2&pk_id=3'];
        yield 'an undocumented share key on youtube' => ['https://www.youtube.com/watch?v=x&si=y&feature=share'];
        yield 'an undocumented share key on spotify' => ['https://open.spotify.com/track/1?si=z'];
        yield 'an undocumented share key at the times' => ['https://www.nytimes.com/2026/a.html?smid=url-share'];
    }

    #[DataProvider('contentUrls')]
    public function testKeysThatNameThePageStay(string $url): void
    {
        self::assertSame($url, TrackingFreeUrl::of($url));
    }

    public function testKeptPairsKeepTheirOrderAndBytes(): void
    {
        self::assertSame(
            'https://example.com/a?b=2&a=%20&c',
            TrackingFreeUrl::of('https://example.com/a?b=2&utm_source=x&a=%20&c'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function untrackedUrls(): iterable
    {
        yield 'an uppercase host' => ['HTTPS://Example.COM/A'];
        yield 'a default port' => ['https://example.com:443/a'];
        yield 'a bare question mark' => ['https://example.com/a?'];
        yield 'an empty pair' => ['https://example.com/a?a=1&&b=2'];
        yield 'an anchor' => ['https://example.com/a#section-3'];
        yield 'a text fragment' => ['https://example.com/a#:~:text=foo'];
        yield 'a fragment holding a question mark' => ['https://example.com/a#x?utm_source=y'];
    }

    #[DataProvider('untrackedUrls')]
    public function testAUrlWithoutTrackingComesBackByteForByte(string $url): void
    {
        self::assertSame($url, TrackingFreeUrl::of($url));
    }

    /** @return iterable<string, array{string, string}> */
    public static function fullyTrackedQueries(): iterable
    {
        yield 'no fragment' => ['https://example.com/a?utm_source=x&fbclid=y', 'https://example.com/a'];
        yield 'a fragment' => ['https://example.com/a?utm_source=x#top', 'https://example.com/a#top'];
    }

    #[DataProvider('fullyTrackedQueries')]
    public function testTheQuestionMarkGoesWhenEveryPairGoes(string $url, string $expected): void
    {
        self::assertSame($expected, TrackingFreeUrl::of($url));
    }

    /** @return iterable<string, array{string, string}> */
    public static function misEscapedUrls(): iterable
    {
        yield 'every pair tracks' => [
            'https://example.com/a?utm_source=feed&amp;utm_medium=rss',
            'https://example.com/a',
        ];
        yield 'a content key stays' => [
            'https://example.com/a?id=5&amp;utm_medium=rss',
            'https://example.com/a?id=5',
        ];
        yield 'a content key behind amp; stays' => [
            'https://example.com/a?x=1&amp;id=5',
            'https://example.com/a?x=1&amp;id=5',
        ];
    }

    #[DataProvider('misEscapedUrls')]
    public function testAMisEscapedAmpersandStillHidesNoTracker(string $url, string $expected): void
    {
        self::assertSame($expected, TrackingFreeUrl::of($url));
    }

    /** @return iterable<string, array{string}> */
    public static function trackingFragments(): iterable
    {
        yield 'piano' => ['xtor=RSS-3-[lestitres]'];
        yield 'echobox' => ['Echobox=1762000000'];
        yield 'two trackers' => ['utm_source=x&at_medium=y'];
    }

    #[DataProvider('trackingFragments')]
    public function testATrackingFragmentGoes(string $fragment): void
    {
        self::assertSame('https://example.com/a?id=1', TrackingFreeUrl::of('https://example.com/a?id=1#' . $fragment));
    }

    /** @return iterable<string, array{string}> */
    public static function navigationFragments(): iterable
    {
        yield 'an anchor' => ['comment-5'];
        yield 'a text fragment' => [':~:text=a,b'];
        yield 'a tracker beside a chapter' => ['xtor=1&chapter=2'];
        yield 'a pair that does not track' => ['a=b'];
        yield 'a tracking key without a value' => ['utm_source'];
        yield 'an empty fragment' => [''];
    }

    #[DataProvider('navigationFragments')]
    public function testANavigationFragmentStays(string $fragment): void
    {
        $url = 'https://example.com/a#' . $fragment;

        self::assertSame($url, TrackingFreeUrl::of($url));
    }

    /** @return iterable<string, array{string}> */
    public static function nonAbsoluteUrls(): iterable
    {
        yield 'not a url' => ['not a url?utm_source=x'];
        yield 'a relative url' => ['/relative?utm_source=x'];
        yield 'a protocol-relative url' => ['//example.com/a?utm_source=x'];
        yield 'an empty string' => [''];
    }

    #[DataProvider('nonAbsoluteUrls')]
    public function testANonAbsoluteUrlComesBackUnchanged(string $url): void
    {
        self::assertSame($url, TrackingFreeUrl::of($url));
    }

    public function testNoUrlStaysNone(): void
    {
        self::assertNull(TrackingFreeUrl::orNull(null));
        self::assertSame('https://example.com/a', TrackingFreeUrl::orNull('https://example.com/a?gclid=1'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function queries(): iterable
    {
        yield 'a tracker between content keys' => ['b=2&utm_source=x&a=1', 'b=2&a=1'];
        yield 'only trackers' => ['utm_source=x&gclid=y', ''];
        yield 'no tracker' => ['id=1', 'id=1'];
    }

    #[DataProvider('queries')]
    public function testTheQueryKeepsItsContentPairs(string $query, string $expected): void
    {
        self::assertSame($expected, TrackingFreeUrl::query($query));
    }

    /** @return iterable<string, array{string}> */
    public static function everyExample(): iterable
    {
        $urls = [
            'https://example.com/a?utm_source=1',
            'https://example.com/a?b=2&utm_source=x&a=%20&c',
            'https://example.com/a?id=1#xtor=RSS-3',
            'https://example.com/a?id=5&amp;utm_medium=rss',
            'https://example.com/a?utm_source=x&',
            'HTTPS://Example.COM:443/A?utm_source=x#top',
        ];
        $providers = [
            self::contentUrls(),
            self::untrackedUrls(),
            self::nonAbsoluteUrls(),
            self::fullyTrackedQueries(),
            self::misEscapedUrls(),
        ];
        foreach ($providers as $examples) {
            foreach ($examples as [$url]) {
                $urls[] = $url;
            }
        }
        foreach (array_unique($urls) as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('everyExample')]
    public function testCleaningAgreesWithTheHashAndIsIdempotent(string $url): void
    {
        $normalizer = new UrlNormalizer();
        $cleaned = TrackingFreeUrl::of($url);

        self::assertSame($normalizer->normalize($url), $normalizer->normalize($cleaned));
        self::assertSame($cleaned, TrackingFreeUrl::of($cleaned));
    }
}
