<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Enum\SocialNetwork;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\FollowMatcher;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Pass\FollowMatching;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class FollowMatcherTest extends TestCase
{
    private const string RESOLVE = 'GET https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=';

    private function matcher(RoutedHttpClient $http): FollowMatcher
    {
        $guard = new UrlGuard(new StaticDnsResolver(['public.api.bsky.app' => ['93.184.216.34']]), new IpValidator());

        return new FollowMatcher(new BlueskyIdentityResolver(FetchWiring::guardedApiClient($http->client(), $guard)));
    }

    private static function bob(): FollowedAccountModel
    {
        return new FollowedAccountModel(
            'did:plc:bob',
            '@bob.example.com',
            'Bob',
            'https://bsky.app/profile/did:plc:bob/rss',
        );
    }

    private static function blueskyFeed(int $subscriptionId, string $url): ProfileFeedSubscriptionModel
    {
        $identity = ProfileFeedUrl::identityOf(SocialNetwork::Bluesky, $url);
        self::assertNotNull($identity);

        return new ProfileFeedSubscriptionModel($subscriptionId, $identity, $url, 'Title ' . $subscriptionId);
    }

    private static function mastodonFeed(int $subscriptionId, string $url): ProfileFeedSubscriptionModel
    {
        $identity = ProfileFeedUrl::identityOf(SocialNetwork::Mastodon, $url);
        self::assertNotNull($identity);

        return new ProfileFeedSubscriptionModel($subscriptionId, $identity, $url, 'Title ' . $subscriptionId);
    }

    /**
     * @param list<ImportCandidateModel> $candidates
     *
     * @return list<array{string, string, ?int}>
     */
    private static function summary(array $candidates): array
    {
        return array_map(
            static fn (ImportCandidateModel $candidate): array => [
                $candidate->account->handle,
                $candidate->state->value,
                $candidate->subscriptionId,
            ],
            $candidates,
        );
    }

    public function testAFeedKeyedByTheFollowsDidIsSubscribed(): void
    {
        $candidates = $this->matcher(new RoutedHttpClient([]))->match(
            new FollowMatching(SocialNetwork::Bluesky, [self::bob()]),
            [self::blueskyFeed(1, 'https://bsky.app/profile/did:plc:bob/rss')],
        );

        self::assertSame([['@bob.example.com', 'subscribed', 1]], self::summary($candidates));
    }

    public function testAFeedKeyedByTheFollowsCurrentHandleMatchesWithoutANetworkCall(): void
    {
        $candidates = $this->matcher(new RoutedHttpClient([]))->match(
            new FollowMatching(SocialNetwork::Bluesky, [self::bob()]),
            [self::blueskyFeed(1, 'https://bsky.app/profile/Bob.Example.com/rss')],
        );

        self::assertSame([['@bob.example.com', 'subscribed', 1]], self::summary($candidates));
    }

    public function testAFeedKeyedByAnOldHandleIsResolvedToItsDid(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE . 'old-bob.bsky.social' => new MockResponse('{"did":"did:plc:bob"}'),
        ]);

        $candidates = $this->matcher($http)->match(
            new FollowMatching(SocialNetwork::Bluesky, [self::bob()]),
            [self::blueskyFeed(1, 'https://bsky.app/profile/old-bob.bsky.social/rss')],
        );

        self::assertSame([['@bob.example.com', 'subscribed', 1]], self::summary($candidates));
    }

    public function testAnUnresolvableHandleIsNoLongerFollowedWithoutAnError(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE . 'gone.bsky.social' => new MockResponse(
                '{"error":"InvalidRequest"}',
                ['http_code' => Response::HTTP_BAD_REQUEST],
            ),
        ]);

        $candidates = $this->matcher($http)->match(
            new FollowMatching(SocialNetwork::Bluesky, [self::bob()]),
            [self::blueskyFeed(2, 'https://bsky.app/profile/gone.bsky.social/rss')],
        );

        self::assertSame(
            [['@bob.example.com', 'new', null], ['@gone.bsky.social', 'no_longer_followed', 2]],
            self::summary($candidates),
        );
    }

    public function testAnUnreachableResolverStopsResolvingTheRest(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE . 'first.bsky.social' => new MockResponse(
                '',
                ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE],
            ),
        ]);

        $candidates = $this->matcher($http)->match(
            new FollowMatching(SocialNetwork::Bluesky, [self::bob()]),
            [
                self::blueskyFeed(1, 'https://bsky.app/profile/first.bsky.social/rss'),
                self::blueskyFeed(2, 'https://bsky.app/profile/second.bsky.social/rss'),
            ],
        );

        self::assertCount(1, $http->requests);
        self::assertCount(3, $candidates);
    }

    public function testAtMostTwentyFiveHandlesAreResolvedPerLoad(): void
    {
        $routes = [];
        $feeds = [];
        foreach (range(1, 26) as $index) {
            $routes[self::RESOLVE . 'h' . $index . '.bsky.social'] = new MockResponse(
                '{"error":"InvalidRequest"}',
                ['http_code' => Response::HTTP_BAD_REQUEST],
            );
            $feeds[] = self::blueskyFeed($index, 'https://bsky.app/profile/h' . $index . '.bsky.social/rss');
        }
        $http = new RoutedHttpClient($routes);

        $this->matcher($http)->match(new FollowMatching(SocialNetwork::Bluesky, [self::bob()]), $feeds);

        self::assertCount(25, $http->requests);
    }

    public function testTwoFeedsOfOneFollowLeaveNothingBehind(): void
    {
        $candidates = $this->matcher(new RoutedHttpClient([]))->match(
            new FollowMatching(SocialNetwork::Bluesky, [self::bob()]),
            [
                self::blueskyFeed(1, 'https://bsky.app/profile/did:plc:bob/rss'),
                self::blueskyFeed(2, 'https://bsky.app/profile/bob.example.com/rss'),
            ],
        );

        self::assertSame([['@bob.example.com', 'subscribed', 1]], self::summary($candidates));
    }

    public function testAMastodonFeedMatchesByIdentityOrByTheFollowsOwnFeedAndNeverResolves(): void
    {
        $follows = [
            new FollowedAccountModel(
                'bob@mastodon.example',
                '@bob@mastodon.example',
                'Bob',
                'https://mastodon.example/@bob.rss',
            ),
            new FollowedAccountModel(
                'carol@example.com',
                '@carol@example.com',
                'Carol',
                'https://social.example.com/@carol.rss',
            ),
        ];

        $candidates = $this->matcher(new RoutedHttpClient([]))->match(
            new FollowMatching(SocialNetwork::Mastodon, $follows),
            [
                self::mastodonFeed(1, 'https://mastodon.example/@Bob.rss'),
                self::mastodonFeed(2, 'https://social.example.com/@carol.rss'),
                self::mastodonFeed(3, 'https://other.example/@dave.rss'),
            ],
        );

        self::assertSame([
            ['@bob@mastodon.example', 'subscribed', 1],
            ['@carol@example.com', 'subscribed', 2],
            ['@dave@other.example', 'no_longer_followed', 3],
        ], self::summary($candidates));
    }

    public function testANoLongerFollowedFeedCarriesItsSubscriptionsTitleAndUrl(): void
    {
        $candidates = $this->matcher(new RoutedHttpClient([]))->match(
            new FollowMatching(SocialNetwork::Bluesky, []),
            [self::blueskyFeed(3, 'https://bsky.app/profile/did:plc:gone/rss')],
        );

        self::assertEquals(
            new FollowedAccountModel(
                'did:plc:gone',
                'did:plc:gone',
                'Title 3',
                'https://bsky.app/profile/did:plc:gone/rss',
            ),
            $candidates[0]->account,
        );
    }
}
