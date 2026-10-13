<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\StoredCredential;
use App\Service\Social\TimelineFetcher\ConcurrentTimelineFetcher;
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

    private const string BLUESKY_HOST = 'pds.example.test';
    private const string MASTODON_HOST = 'mastodon.example';
    private const string OTHER_MASTODON_HOST = 'other.example';
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

    private static function mastodonApiClient(HttpClientInterface $http): MastodonApiClient
    {
        return new MastodonApiClient(FetchWiring::guardedApiClient($http, self::guardFor(self::MASTODON_HOST)));
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

    /** The timeline fetcher over the real engine; every test host resolves to one public address. */
    public static function timelineFetcher(
        HttpClientInterface $http,
        ArrayAdapter $cache,
        int $accountConcurrency = 3,
    ): ConcurrentTimelineFetcher {
        $guard = new UrlGuard(new StaticDnsResolver([
            self::BLUESKY_HOST => [self::PUBLIC_ADDRESS],
            self::MASTODON_HOST => [self::PUBLIC_ADDRESS],
            self::OTHER_MASTODON_HOST => [self::PUBLIC_ADDRESS],
        ]), new IpValidator());

        return new ConcurrentTimelineFetcher(
            FetchWiring::requestEngine($http, $guard, FetchWiring::egressProxy(null), 8),
            self::timelineSources($http, $cache),
            $accountConcurrency,
        );
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

    private static function guardFor(string $host): UrlGuard
    {
        return new UrlGuard(new StaticDnsResolver([$host => [self::PUBLIC_ADDRESS]]), new IpValidator());
    }
}
