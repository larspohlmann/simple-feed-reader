<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Url\Support;

use App\Service\Foundation\Url\Pass\TrackedUrl;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use App\Service\Foundation\Url\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrackingFreeUrlTest extends TestCase
{
    /** Never stripped, on any host: gift and gift-like keys. */
    private const array NEVER_STRIPPED_KEYS = [
        'unlocked_article_code', 'gift', 'pwapi_token', 'accesstoken', 'sharetype', 'gifttoken',
        'giftcode', 'gift_token', 'share_token', 'access_token',
        'freebie', 'shared', 'view_token', 'reduced', 'premium', 'leadsource',
        'link_source', 'taid',
        'mkt_tok', 'oly_enc_id', 'traffic_source', 'ref', 'at_bbc_team', 'at_specific',
    ];
    private const array NEVER_STRIPPED_KEYS_BY_DOMAIN = [
        'wsj.com' => ['st', 'reflink'],
        'nytimes.com' => ['source', 'smtyp'],
    ];
    private const array DOCUMENTED_KEYS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'utm_souce',
        'gclid', 'gclsrc', 'dclid', 'gbraid', 'wbraid', 'srsltid', '_gl',
        'fbclid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id', 'yclid',
        'mc_cid', 'mc_eid', 'mc_tc',
        '_hsenc', '_hsmi', '__hstc', '__hssc', '__hsfp',
        'adobe_mc', 'vero_id', 'vero_conv',
        'mtm_campaign', 'mtm_source', 'mtm_medium', 'mtm_keyword', 'mtm_kwd', 'mtm_content', 'mtm_cid', 'mtm_group',
        'mtm_placement', 'pk_campaign', 'pk_kwd', 'pk_keyword', 'pk_source', 'pk_medium',
        'pk_content', 'pk_cid', 'piwik_campaign', 'piwik_kwd', 'matomo_campaign', 'matomo_kwd',
        'at_medium', 'at_campaign', 'at_platform', 'at_creation', 'at_variant', 'at_network', 'at_term',
        'xtor', 'wt_mc', 'wt_cc1', 'echobox',
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
        yield 'upper-case piano key' => ['AT_Medium'];
        yield 'percent-encoded matomo key' => ['mtm%5Fcampaign'];
    }

    #[DataProvider('documentedKeys')]
    public function testEveryDocumentedKeyIsStripped(string $key): void
    {
        self::assertSame(
            'https://example.com/a',
            TrackingFreeUrl::stripTracking('https://example.com/a?' . $key . '=1'),
        );
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
        yield 'a share key off its host' => ['https://example.com/?si=1&feature=x&smid=y&smtyp=z'];
        yield 'a share key on a host that only ends like its own' => ['https://notyoutube.com/?si=1'];
        yield 'a share key on a host that only contains its own' => ['https://youtube.com.example.org/?si=1'];
        yield 'a key that only starts like a piano, mapp or matomo key' => [
            'https://example.com/a?at_foo=x&wt_zmc=y&mtm_foo=z',
        ];
        yield 'a marketo token' => ['https://example.com/unsubscribe?mkt_tok=abc'];
    }

    #[DataProvider('contentUrls')]
    public function testKeysThatNameThePageStay(string $url): void
    {
        self::assertSame($url, TrackingFreeUrl::stripTracking($url));
    }

    /** @return iterable<string, array{string, string}> */
    public static function sharedOnTheirHost(): iterable
    {
        yield 'youtube' => [
            'https://www.youtube.com/watch?v=x&si=y&feature=share',
            'https://www.youtube.com/watch?v=x',
        ];
        yield 'youtube without www' => ['https://youtube.com/watch?v=x&si=y', 'https://youtube.com/watch?v=x'];
        yield 'mobile youtube' => ['https://m.youtube.com/watch?v=x&feature=share', 'https://m.youtube.com/watch?v=x'];
        yield 'youtube music' => ['https://music.youtube.com/watch?v=x&si=y', 'https://music.youtube.com/watch?v=x'];
        yield 'a short youtube link keeps its time' => ['https://youtu.be/x?si=y&t=30', 'https://youtu.be/x?t=30'];
        yield 'spotify' => ['https://open.spotify.com/track/1?si=z', 'https://open.spotify.com/track/1'];
        yield 'an upper-case host' => ['https://OPEN.Spotify.com/track/1?si=z', 'https://OPEN.Spotify.com/track/1'];
        yield 'the times keeps its gift link' => [
            'https://www.nytimes.com/2026/a.html?smid=url-share&smtyp=cur&unlocked_article_code=k',
            'https://www.nytimes.com/2026/a.html?smtyp=cur&unlocked_article_code=k',
        ];
        yield 'a share fragment on its host' => ['https://youtu.be/x#si=y', 'https://youtu.be/x'];
    }

    #[DataProvider('sharedOnTheirHost')]
    public function testShareKeysGoOnlyOnTheirHost(string $url, string $expected): void
    {
        self::assertSame($expected, TrackingFreeUrl::stripTracking($url));
    }

    /** @return iterable<string, array{string}> */
    public static function undocumentedTrackers(): iterable
    {
        $keys = ['mrfcid', 'sara_ref', 'igshid', 'igsh', '_bhlid', 'sfmc_id', 'rb_clickid'];
        foreach ($keys as $key) {
            yield $key => [$key];
        }
        yield 'substack redirect flag' => ['triedRedirect'];
        yield 'substack welcome flag' => ['showWelcomeOnShare'];
    }

    #[DataProvider('undocumentedTrackers')]
    public function testAKeyThatNeverCarriesContentGoesEverywhere(string $key): void
    {
        self::assertSame(
            'https://example.com/a?id=1',
            TrackingFreeUrl::stripTracking('https://example.com/a?id=1&' . $key . '=x'),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function hostScopedTrackers(): iterable
    {
        yield 'substack r' => ['https://www.example.substack.com/p/a?r=2abc', 'https://www.example.substack.com/p/a'];
        yield 'x s and t' => ['https://x.com/a/status/1?s=20&t=abc', 'https://x.com/a/status/1'];
        yield 'twitter s and t' => ['https://twitter.com/a/status/1?s=46&t=abc', 'https://twitter.com/a/status/1'];
        yield 'instagram share keys' => [
            'https://www.instagram.com/p/abc/?exln=1&srtk=2&rpxt=3',
            'https://www.instagram.com/p/abc/',
        ];
    }

    #[DataProvider('hostScopedTrackers')]
    public function testAShortKeyGoesOnlyOnItsHost(string $url, string $expected): void
    {
        self::assertSame($expected, TrackingFreeUrl::stripTracking($url));
    }

    /** @return iterable<string, array{string}> */
    public static function shortKeysOffTheirHost(): iterable
    {
        yield 'r elsewhere' => ['https://example.com/a?r=2'];
        yield 's elsewhere' => ['https://example.com/a?s=20'];
        yield 'a youtube time' => ['https://www.youtube.com/watch?v=x&t=90'];
        yield 'exln elsewhere' => ['https://example.com/a?exln=1'];
    }

    #[DataProvider('shortKeysOffTheirHost')]
    public function testAShortKeyStaysOffItsHost(string $url): void
    {
        self::assertSame($url, TrackingFreeUrl::stripTracking($url));
    }

    /** @return iterable<string, array{string, string}> */
    public static function feedMarkers(): iterable
    {
        yield 'cbc cmp' => ['https://www.cbc.ca/news/a-1.2?cmp=rss', 'https://www.cbc.ca/news/a-1.2'];
        yield 'slate via' => ['https://slate.com/a.html?via=rss', 'https://slate.com/a.html'];
        yield 'feed' => ['https://example.com/a?id=1&cmp=Feed', 'https://example.com/a?id=1'];
        yield 'atom' => ['https://example.com/a?via=atom', 'https://example.com/a'];
    }

    #[DataProvider('feedMarkers')]
    public function testAFeedMarkerGoes(string $url, string $expected): void
    {
        self::assertSame($expected, TrackingFreeUrl::stripTracking($url));
    }

    /** @return iterable<string, array{string}> */
    public static function keysWithAnotherValue(): iterable
    {
        yield 'a campaign code' => ['https://example.com/a?cmp=spring'];
        yield 'a referrer' => ['https://example.com/a?ref=home'];
        yield 'a via value that only starts with rss' => ['https://example.com/a?via=rssfeed'];
        yield 'a key without value' => ['https://example.com/a?ref'];
        yield 'a value holding an equals sign' => ['https://example.com/a?cmp=rss=spring'];
        yield 'a ref feed marker' => ['https://www.derstandard.at/story/1?ref=RSS'];
    }

    #[DataProvider('keysWithAnotherValue')]
    public function testAKeyWithAnotherValueStays(string $url): void
    {
        self::assertSame($url, TrackingFreeUrl::stripTracking($url));
    }

    public function testOnlyTheVendorsExactKeyGoes(): void
    {
        self::assertSame(
            'https://www.bbc.com/news/a?at_bbc_team=x',
            TrackingFreeUrl::stripTracking('https://www.bbc.com/news/a?at_medium=RSS&at_bbc_team=x'),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function giftLinks(): iterable
    {
        yield 'nyt' => [
            'https://www.nytimes.com/2026/a.html?unlocked_article_code=k&smid=url-share',
            'https://www.nytimes.com/2026/a.html?unlocked_article_code=k',
        ];
        yield 'the atlantic' => [
            'https://www.theatlantic.com/a/1/?gift=g&utm_source=copy-link&utm_medium=social',
            'https://www.theatlantic.com/a/1/?gift=g',
        ];
        yield 'washington post' => [
            'https://www.washingtonpost.com/a/?pwapi_token=t&utm_campaign=x',
            'https://www.washingtonpost.com/a/?pwapi_token=t',
        ];
        yield 'bloomberg' => [
            'https://www.bloomberg.com/news/a?accessToken=t&leadSource=uverify',
            'https://www.bloomberg.com/news/a?accessToken=t&leadSource=uverify',
        ];
        yield 'ft' => [
            'https://www.ft.com/content/1?accessToken=t&sharetype=gift&utm_source=x',
            'https://www.ft.com/content/1?accessToken=t&sharetype=gift',
        ];
        yield 'wsj' => ['https://www.wsj.com/a?st=abc&mod=share', 'https://www.wsj.com/a?st=abc&mod=share'];
        yield 'spiegel' => [
            'https://www.spiegel.de/a?giftToken=g&sara_ref=re-so',
            'https://www.spiegel.de/a?giftToken=g',
        ];
        yield 'generic' => [
            'https://example.com/a?giftcode=1&gift_token=2&share_token=3&access_token=4&fbclid=5',
            'https://example.com/a?giftcode=1&gift_token=2&share_token=3&access_token=4',
        ];
    }

    #[DataProvider('giftLinks')]
    public function testAGiftKeyIsNeverStripped(string $url, string $expected): void
    {
        self::assertSame($expected, TrackingFreeUrl::stripTracking($url));
    }

    public function testKeptPairsKeepTheirOrderAndBytes(): void
    {
        self::assertSame(
            'https://example.com/a?b=2&a=%20&c',
            TrackingFreeUrl::stripTracking('https://example.com/a?b=2&utm_source=x&a=%20&c'),
        );
        self::assertSame(
            'https://example.com/a?id=1#top',
            TrackingFreeUrl::stripTracking('https://example.com/a?id=1&utm_source=x#top'),
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
        self::assertSame($url, TrackingFreeUrl::stripTracking($url));
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
        self::assertSame($expected, TrackingFreeUrl::stripTracking($url));
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
        self::assertSame($expected, TrackingFreeUrl::stripTracking($url));
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
        self::assertSame(
            'https://example.com/a?id=1',
            TrackingFreeUrl::stripTracking('https://example.com/a?id=1#' . $fragment),
        );
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
        yield 'a custom piano key' => ['at_foo=x'];
    }

    #[DataProvider('navigationFragments')]
    public function testANavigationFragmentStays(string $fragment): void
    {
        $url = 'https://example.com/a#' . $fragment;

        self::assertSame($url, TrackingFreeUrl::stripTracking($url));
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
        self::assertSame($url, TrackingFreeUrl::stripTracking($url));
    }

    public function testNoUrlStaysNone(): void
    {
        self::assertNull(TrackingFreeUrl::orNull(null));
        self::assertSame('https://example.com/a', TrackingFreeUrl::orNull('https://example.com/a?gclid=1'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function queries(): iterable
    {
        yield 'a tracker between content keys' => ['b=2&utm_source=x&a=1', 'example.com', 'b=2&a=1'];
        yield 'only trackers' => ['utm_source=x&gclid=y', 'example.com', ''];
        yield 'no tracker' => ['id=1', 'example.com', 'id=1'];
        yield 'a share key on its host' => ['v=x&si=y', 'www.youtube.com', 'v=x'];
        yield 'a share key off its host' => ['v=x&si=y', 'example.com', 'v=x&si=y'];
    }

    #[DataProvider('queries')]
    public function testTheQueryKeepsItsContentPairs(string $query, string $host, string $expected): void
    {
        self::assertSame($expected, TrackingFreeUrl::query($query, $host));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function strippedKeys(): iterable
    {
        yield 'query and fragment' => [
            'https://example.com/a?id=1&UTM_Source=x&cmp=rss#xtor=RSS-3',
            ['utm_source', 'cmp', 'xtor'],
        ];
        yield 'a navigation fragment' => ['https://example.com/a?fbclid=1#utm_source=x&section=2', ['fbclid']];
        yield 'a fragment of two trackers' => [
            'https://example.com/a#utm_source=x&at_medium=y',
            ['utm_source', 'at_medium'],
        ];
        yield 'a gift key' => ['https://www.theatlantic.com/a?gift=g&id=1', []];
        yield 'nothing to strip' => ['https://example.com/a', []];
        yield 'no host' => ['/a?utm_source=x', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('strippedKeys')]
    public function testTheStrippedKeysAreNamedAsTheCleanerReadsThem(string $url, array $expected): void
    {
        self::assertSame($expected, TrackingFreeUrl::read($url)->strippedKeys());
    }

    /** @return iterable<string, array{string}> */
    public static function neverStrippedKeysOnEveryHost(): iterable
    {
        $domainRules = new \ReflectionClassConstant(TrackedUrl::class, 'KEYS_BY_DOMAIN')->getValue();
        self::assertIsArray($domainRules);
        $hosts = ['example.com', ...array_map(strval(...), array_keys($domainRules))];
        foreach ($hosts as $host) {
            foreach (self::NEVER_STRIPPED_KEYS as $key) {
                yield $key . ' on ' . $host => ['https://www.' . $host . '/a?' . $key . '=rss&' . $key . '=1'];
            }
        }
        foreach (self::NEVER_STRIPPED_KEYS_BY_DOMAIN as $domain => $keys) {
            foreach ($keys as $key) {
                yield $key . ' on ' . $domain => ['https://www.' . $domain . '/a?' . $key . '=rss&' . $key . '=1'];
            }
        }
    }

    #[DataProvider('neverStrippedKeysOnEveryHost')]
    public function testNoRuleEverMatchesAKeyThatMayNameContent(string $url): void
    {
        self::assertSame([], TrackingFreeUrl::read($url)->strippedKeys());
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
            'https://www.bbc.com/news/a?at_medium=RSS&at_bbc_team=x',
        ];
        $providers = [
            self::contentUrls(),
            self::untrackedUrls(),
            self::nonAbsoluteUrls(),
            self::fullyTrackedQueries(),
            self::misEscapedUrls(),
            self::sharedOnTheirHost(),
            self::hostScopedTrackers(),
            self::feedMarkers(),
            self::giftLinks(),
            self::keysWithAnotherValue(),
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
        $cleaned = TrackingFreeUrl::stripTracking($url);

        self::assertSame($normalizer->normalize($url), $normalizer->normalize($cleaned));
        self::assertSame($cleaned, TrackingFreeUrl::stripTracking($cleaned));
    }
}
