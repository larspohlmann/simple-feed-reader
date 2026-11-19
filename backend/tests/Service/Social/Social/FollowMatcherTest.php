<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Support\BlueskyProfileFeed;
use App\Service\Social\Mastodon\Support\MastodonProfileFeed;
use App\Service\Social\Social\FollowMatcher;
use App\Service\Social\Social\Model\FollowedAccountModel;
use App\Service\Social\Social\Model\ImportCandidateModel;
use App\Service\Social\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Social\Pass\FollowMatching;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialRuleWiring;
use App\Tests\Support\StubHiddenProfiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class FollowMatcherTest extends TestCase
{
    private const string RESOLVE = 'GET https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=';

    private RoutedHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new RoutedHttpClient([]);
    }

    /**
     * @param list<FollowedAccountModel>         $follows
     * @param list<ProfileFeedSubscriptionModel> $profileFeeds
     *
     * @return list<ImportCandidateModel>
     */
    private function match(SocialNetwork $network, array $follows, array $profileFeeds): array
    {
        $importRule = SocialRuleWiring::importRules($this->http, new StubHiddenProfiles())->selectRule($network);
        $matching = new FollowMatching(SocialRuleWiring::profileFeeds()->selectRule($network), $importRule, $follows);

        return new FollowMatcher()->match($matching, $importRule, $profileFeeds);
    }

    private static function bob(): FollowedAccountModel
    {
        return new FollowedAccountModel(
            'did:plc:bob',
            '@bob.example.com',
            'Bob',
            'https://bsky.app/profile/did:plc:bob/rss',
            null,
        );
    }

    private static function mastodonFollow(string $identity, string $feedUrl): FollowedAccountModel
    {
        return new FollowedAccountModel($identity, '@' . $identity, null, $feedUrl, null);
    }

    private static function blueskyFeed(int $subscriptionId, string $url): ProfileFeedSubscriptionModel
    {
        $identity = BlueskyProfileFeed::parseIdentity($url);
        self::assertNotNull($identity);

        return new ProfileFeedSubscriptionModel($subscriptionId, $identity, $url, 'Title ' . $subscriptionId, null);
    }

    private static function mastodonFeed(int $subscriptionId, string $url): ProfileFeedSubscriptionModel
    {
        $identity = MastodonProfileFeed::parseIdentity($url);
        self::assertNotNull($identity);

        return new ProfileFeedSubscriptionModel($subscriptionId, $identity, $url, 'Title ' . $subscriptionId, null);
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
        $candidates = $this->match(
            SocialNetwork::Bluesky,
            [self::bob()],
            [self::blueskyFeed(1, 'https://bsky.app/profile/did:plc:bob/rss')],
        );

        self::assertSame([['@bob.example.com', 'subscribed', 1]], self::summary($candidates));
    }

    public function testAFeedKeyedByTheFollowsCurrentHandleMatchesWithoutANetworkCall(): void
    {
        $candidates = $this->match(
            SocialNetwork::Bluesky,
            [self::bob()],
            [self::blueskyFeed(1, 'https://bsky.app/profile/Bob.Example.com/rss')],
        );

        self::assertSame([['@bob.example.com', 'subscribed', 1]], self::summary($candidates));
    }

    public function testAFeedKeyedByAnOldHandleIsResolvedToItsDid(): void
    {
        $this->http = new RoutedHttpClient([
            self::RESOLVE . 'old-bob.bsky.social' => new MockResponse('{"did":"did:plc:bob"}'),
        ]);

        $candidates = $this->match(
            SocialNetwork::Bluesky,
            [self::bob()],
            [self::blueskyFeed(1, 'https://bsky.app/profile/old-bob.bsky.social/rss')],
        );

        self::assertSame([['@bob.example.com', 'subscribed', 1]], self::summary($candidates));
    }

    public function testAnUnresolvableHandleIsNoLongerFollowedWithoutAnError(): void
    {
        $this->http = new RoutedHttpClient([
            self::RESOLVE . 'gone.bsky.social' => new MockResponse(
                '{"error":"InvalidRequest"}',
                ['http_code' => Response::HTTP_BAD_REQUEST],
            ),
        ]);

        $candidates = $this->match(
            SocialNetwork::Bluesky,
            [self::bob()],
            [self::blueskyFeed(2, 'https://bsky.app/profile/gone.bsky.social/rss')],
        );

        self::assertSame(
            [['@bob.example.com', 'new', null], ['@gone.bsky.social', 'no_longer_followed', 2]],
            self::summary($candidates),
        );
    }

    public function testAnUnreachableResolverStopsResolvingTheRest(): void
    {
        $this->http = new RoutedHttpClient([
            self::RESOLVE . 'first.bsky.social' => new MockResponse(
                '',
                ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE],
            ),
        ]);

        $candidates = $this->match(
            SocialNetwork::Bluesky,
            [self::bob()],
            [
                self::blueskyFeed(1, 'https://bsky.app/profile/first.bsky.social/rss'),
                self::blueskyFeed(2, 'https://bsky.app/profile/second.bsky.social/rss'),
            ],
        );

        self::assertCount(3, $this->http->requests);
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
        $this->http = new RoutedHttpClient($routes);

        $this->match(SocialNetwork::Bluesky, [self::bob()], $feeds);

        self::assertCount(25, $this->http->requests);
    }

    public function testTwoFeedsOfOneFollowLeaveNothingBehind(): void
    {
        $candidates = $this->match(
            SocialNetwork::Bluesky,
            [self::bob()],
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
            self::mastodonFollow('bob@mastodon.example', 'https://mastodon.example/@bob.rss'),
            self::mastodonFollow('carol@example.com', 'https://social.example.com/@carol.rss'),
        ];

        $candidates = $this->match(
            SocialNetwork::Mastodon,
            $follows,
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

    public function testAFollowOnAnInstanceWithAPortMatchesItsFeedOnThatPort(): void
    {
        $bob = self::mastodonFollow('bob@host.example:8443', 'https://host.example:8443/@bob.rss');

        $candidates = $this->match(
            SocialNetwork::Mastodon,
            [$bob],
            [self::mastodonFeed(1, 'https://host.example:8443/@bob.rss')],
        );

        self::assertSame([['@bob@host.example:8443', 'subscribed', 1]], self::summary($candidates));
    }

    public function testAFollowListedTwiceIsOfferedOnceAsItsFirstListing(): void
    {
        $again = new FollowedAccountModel(
            'did:plc:bob',
            '@bob.example.com',
            'Bob again',
            'https://bsky.app/profile/did:plc:bob/rss',
            null,
        );

        $candidates = $this->match(
            SocialNetwork::Bluesky,
            [self::bob(), $again],
            [],
        );

        self::assertEquals([ImportCandidateModel::notYetSubscribed(self::bob())], $candidates);
    }

    public function testAFollowListedTwiceInAnotherCaseIsOfferedOnce(): void
    {
        $follows = [
            self::mastodonFollow('bob@mastodon.example', 'https://mastodon.example/@bob.rss'),
            self::mastodonFollow('Bob@mastodon.example', 'https://mastodon.example/@Bob.rss'),
        ];

        $candidates = $this->match(
            SocialNetwork::Mastodon,
            $follows,
            [],
        );

        self::assertSame([['@bob@mastodon.example', 'new', null]], self::summary($candidates));
    }

    public function testANoLongerFollowedFeedCarriesItsSubscriptionsTitleAndUrl(): void
    {
        $candidates = $this->match(
            SocialNetwork::Bluesky,
            [],
            [self::blueskyFeed(3, 'https://bsky.app/profile/did:plc:gone/rss')],
        );

        self::assertEquals(
            new FollowedAccountModel(
                'did:plc:gone',
                'did:plc:gone',
                'Title 3',
                'https://bsky.app/profile/did:plc:gone/rss',
                null,
            ),
            $candidates[0]->account,
        );
    }
}
