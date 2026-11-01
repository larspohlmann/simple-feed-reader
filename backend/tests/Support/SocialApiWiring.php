<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Refresh\PassFetcher\ConcurrentPassFetcher;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\StoredCredential;
use App\Service\Social\TimelineRounds;
use App\Service\Social\TimelineSource\BlueskyTimelineSource;
use App\Service\Social\TimelineSource\MastodonTimelineSource;
use App\Service\Social\TimelineSources;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SocialApiWiring
{
    use AssignsEntityIds;

    public const string RSS_HOST = 'news.example';

    private const string BLUESKY_HOST = 'pds.example.test';
    private const string APP_VIEW_HOST = 'public.api.bsky.app';
    private const string MASTODON_HOST = 'mastodon.example';
    private const string OTHER_MASTODON_HOST = 'other.example';
    private const string ORIGIN_MASTODON_HOST = 'mastodon.social';
    private const string PUBLIC_ADDRESS = '93.184.216.34';
    private const int BLUESKY_ACCOUNT_ID = 3;

    private function __construct()
    {
    }

    public static function blueskySessions(HttpClientInterface $http, ?ArrayAdapter $cache = null): BlueskySessions
    {
        $api = new BlueskyApiClient(FetchWiring::guardedApiClient($http, self::guardFor(self::BLUESKY_HOST)));

        return new BlueskySessions(
            $api,
            new BlueskySessionOpener($api, new StoredCredential(SocialAccounts::cipher())),
            self::blueskySessionCache($cache ?? new ArrayAdapter()),
        );
    }

    public static function blueskySessionCache(ArrayAdapter $cache): BlueskySessionCache
    {
        return new BlueskySessionCache($cache, new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('k', 32))));
    }

    public static function blueskyIdentityResolver(HttpClientInterface $http): BlueskyIdentityResolver
    {
        return new BlueskyIdentityResolver(
            new BlueskyApiClient(FetchWiring::guardedApiClient($http, self::guardFor(self::APP_VIEW_HOST))),
        );
    }

    public static function mastodonApiClient(HttpClientInterface $http): MastodonApiClient
    {
        $guard = self::guardFor(self::MASTODON_HOST, self::ORIGIN_MASTODON_HOST);

        return new MastodonApiClient(FetchWiring::guardedApiClient($http, $guard));
    }

    public static function mastodonAccountApi(HttpClientInterface $http): MastodonAccountApi
    {
        return new MastodonAccountApi(self::mastodonApiClient($http), new StoredCredential(SocialAccounts::cipher()));
    }

    public static function blueskyAccount(): SocialAccount
    {
        $account = SocialAccounts::bluesky();
        self::assignId($account, self::BLUESKY_ACCOUNT_ID);

        return $account;
    }

    public static function blueskySessionAnswer(string $accessJwt): MockResponse
    {
        return new MockResponse(json_encode(
            ['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => $accessJwt],
            \JSON_THROW_ON_ERROR,
        ));
    }

    /** The refresh's pass fetcher over the real engine; every test host, RSS_HOST included, resolves to one address. */
    public static function passFetcher(
        HttpClientInterface $http,
        ArrayAdapter $cache,
        int $accountConcurrency = 3,
    ): ConcurrentPassFetcher {
        $guard = self::guardFor(self::BLUESKY_HOST, self::MASTODON_HOST, self::OTHER_MASTODON_HOST, self::RSS_HOST);

        return new ConcurrentPassFetcher(
            FetchWiring::requestEngine($http, $guard, FetchWiring::egressProxy(null), 8),
            self::timelineRounds($http, $cache, $accountConcurrency),
            2,
        );
    }

    public static function timelineRounds(
        HttpClientInterface $http,
        ArrayAdapter $cache,
        int $accountConcurrency = 3,
    ): TimelineRounds {
        return new TimelineRounds(self::timelineSources($http, $cache), $accountConcurrency, 4);
    }

    public static function timelineSources(HttpClientInterface $http, ArrayAdapter $cache): TimelineSources
    {
        return new TimelineSources(new ServiceLocator([
            BlueskyTimelineSource::network() => static fn (): BlueskyTimelineSource => new BlueskyTimelineSource(
                self::blueskySessions($http, $cache),
            ),
            MastodonTimelineSource::network() => static fn (): MastodonTimelineSource => new MastodonTimelineSource(
                self::mastodonAccountApi($http),
            ),
        ]));
    }

    private static function guardFor(string ...$hosts): UrlGuard
    {
        $addresses = array_fill_keys($hosts, [self::PUBLIC_ADDRESS]);

        return new UrlGuard(new StaticDnsResolver($addresses), new IpValidator());
    }
}
