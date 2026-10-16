<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\CandidateSource;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\CandidateSource\MastodonCandidateSource;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonCandidateSourceTest extends TestCase
{
    private const string VERIFY = 'GET https://mastodon.example/api/v1/accounts/verify_credentials';
    private const string FOLLOWING = 'GET https://mastodon.example/api/v1/accounts/109/following?limit=80';
    private const string NEXT_PAGE = 'https://mastodon.example/api/v1/accounts/109/following?limit=80&max_id=5';
    private const string PREVIOUS_PAGE = 'https://mastodon.example/api/v1/accounts/109/following?since_id=9';
    private const string LISTS = 'GET https://mastodon.example/api/v1/lists';

    private function source(RoutedHttpClient $http): MastodonCandidateSource
    {
        $guard = new UrlGuard(
            new StaticDnsResolver(['mastodon.example' => ['93.184.216.34'], 'evil.example' => ['93.184.216.35']]),
            new IpValidator(),
        );

        return new MastodonCandidateSource(
            new MastodonApiClient(FetchWiring::guardedApiClient($http->client(), $guard)),
            new StoredCredential(SocialAccounts::cipher()),
        );
    }

    /**
     * @param array<string, MockResponse> $overrides
     *
     * @return array<string, MockResponse>
     */
    private function routes(array $overrides = []): array
    {
        return $overrides + [
            self::VERIFY => self::json(['id' => '109', 'username' => 'alice', 'acct' => 'alice']),
            self::FOLLOWING => self::json([]),
            self::LISTS => self::json([]),
        ];
    }

    /** @param array<mixed> $body */
    private static function json(array $body, string $link = ''): MockResponse
    {
        return new MockResponse(
            json_encode($body, \JSON_THROW_ON_ERROR),
            '' === $link ? [] : ['response_headers' => ['Link' => $link]],
        );
    }

    /** @return list<array{id: string, acct: string}> */
    private static function manyFollows(int $count): array
    {
        return array_map(
            static fn (int $index): array => ['id' => (string) $index, 'acct' => 'f' . $index],
            range(1, $count),
        );
    }

    /** @return array{id: string, acct: string, url: string, display_name: string} */
    private static function account(string $accountAddress, string $profileUrl, string $displayName): array
    {
        return [
            'id' => $accountAddress,
            'acct' => $accountAddress,
            'url' => $profileUrl,
            'display_name' => $displayName,
        ];
    }

    private static function followed(string $identity, ?string $displayName, string $feedUrl): FollowedAccountModel
    {
        return new FollowedAccountModel($identity, '@' . $identity, $displayName, $feedUrl, null);
    }

    public function testTheNetworkKeyIsMastodon(): void
    {
        self::assertSame('mastodon', MastodonCandidateSource::network());
    }

    public function testAFollowCarriesItsStillAvatarAndFallsBackToTheAnimatedOne(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWING => self::json([
                self::account('bob', 'https://mastodon.example/@bob', 'Bob') + [
                    'avatar' => 'https://files.example/bob.gif',
                    'avatar_static' => 'https://files.example/bob.png',
                ],
                self::account('carol', 'https://mastodon.example/@carol', 'Carol') + [
                    'avatar' => 'https://files.example/carol.png',
                ],
                self::account('dave', 'https://mastodon.example/@dave', 'Dave') + ['avatar' => 'missing.png'],
            ]),
        ]));

        $follows = $this->source($http)->candidates(SocialAccounts::mastodon())->follows;

        self::assertSame(
            ['https://files.example/bob.png', 'https://files.example/carol.png', null],
            array_map(static fn (FollowedAccountModel $follow): ?string => $follow->avatarUrl, $follows),
        );
    }

    public function testFollowsReadAsUserAtDomainWithTheirProfileFeed(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWING => self::json([
                self::account('bob', 'https://mastodon.example/@bob', 'Bob'),
                self::account('Carol@Other.Example', 'https://other.example/@Carol', ''),
                self::account('dave@pleroma.example', 'https://pleroma.example/users/dave', 'Dave'),
                ['id' => '4', 'acct' => 'eve@insecure.example', 'url' => 'http://insecure.example/@eve'],
                ['id' => '5', 'username' => 'nobody'],
            ]),
        ]));

        $follows = $this->source($http)->candidates(SocialAccounts::mastodon())->follows;

        self::assertEquals([
            self::followed('bob@mastodon.example', 'Bob', 'https://mastodon.example/@bob.rss'),
            self::followed('Carol@other.example', null, 'https://other.example/@Carol.rss'),
            self::followed('dave@pleroma.example', 'Dave', 'https://pleroma.example/@dave.rss'),
            self::followed('eve@insecure.example', null, 'https://insecure.example/@eve.rss'),
        ], $follows);
        foreach ($http->requests as $request) {
            self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $request['headers']);
        }
    }

    public function testFollowingIsPagedThroughTheLinkHeader(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWING => self::json(
                [self::account('bob', 'https://mastodon.example/@bob', '')],
                '<' . self::NEXT_PAGE . '>; rel="next", <' . self::PREVIOUS_PAGE . '>; rel="prev"',
            ),
            'GET ' . self::NEXT_PAGE => self::json([self::account('carol', 'https://mastodon.example/@carol', '')]),
        ]));

        $follows = $this->source($http)->candidates(SocialAccounts::mastodon())->follows;

        self::assertSame(
            ['bob@mastodon.example', 'carol@mastodon.example'],
            array_map(static fn (FollowedAccountModel $follow): string => $follow->identity, $follows),
        );
    }

    public function testANextPageOnAnotherOriginEndsPagingAsTruncated(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWING => self::json(
                [self::account('bob', 'https://mastodon.example/@bob', '')],
                '<https://evil.example/api/v1/accounts/109/following?max_id=5>; rel="next"',
            ),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::mastodon());

        self::assertNotContains('https://evil.example/api/v1/accounts/109/following?max_id=5', $http->requestedUrls());
        self::assertCount(1, $candidates->follows);
        self::assertTrue($candidates->truncated);
    }

    public function testAnInstanceStoredWithItsDefaultPortKeepsPagingALinkWithoutIt(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWING => self::json(
                [self::account('bob', 'https://mastodon.example/@bob', '')],
                '<' . self::NEXT_PAGE . '>; rel="next"',
            ),
            'GET ' . self::NEXT_PAGE => self::json([self::account('carol', 'https://mastodon.example/@carol', '')]),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::mastodonOn('https://mastodon.example:443'));

        self::assertCount(2, $candidates->follows);
        self::assertFalse($candidates->truncated);
    }

    public function testListsAreOfferedAndThereAreNoCustomFeeds(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::LISTS => self::json([['id' => '12', 'title' => 'Friends'], ['id' => '13']]),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::mastodon());

        self::assertEquals([new SocialCollectionModel('12', 'Friends')], $candidates->lists);
        self::assertSame([], $candidates->customFeeds);
    }

    public function testFollowsBeyondTheCapAreCutAndMarkedTruncated(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWING => self::json(
                self::manyFollows(CollectedItemsModel::MAX_ITEMS + 1),
                '<' . self::NEXT_PAGE . '>; rel="next"',
            ),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::mastodon());

        self::assertCount(CollectedItemsModel::MAX_ITEMS, $candidates->follows);
        self::assertTrue($candidates->truncated);
        self::assertNotContains(self::NEXT_PAGE, $http->requestedUrls());
    }

    public function testAPageBeyondTheCapWithANextPageElsewhereIsCutToTheCap(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWING => self::json(
                self::manyFollows(CollectedItemsModel::MAX_ITEMS + 1),
                '<https://evil.example/api/v1/accounts/109/following?max_id=5>; rel="next"',
            ),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::mastodon());

        self::assertCount(CollectedItemsModel::MAX_ITEMS, $candidates->follows);
        self::assertTrue($candidates->truncated);
    }

    public function testExactlyTheCapStillAsksForTheNextPageAndIsNotTruncated(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWING => self::json(
                self::manyFollows(CollectedItemsModel::MAX_ITEMS),
                '<' . self::NEXT_PAGE . '>; rel="next"',
            ),
            'GET ' . self::NEXT_PAGE => self::json([]),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::mastodon());

        self::assertCount(CollectedItemsModel::MAX_ITEMS, $candidates->follows);
        self::assertFalse($candidates->truncated);
        self::assertContains(self::NEXT_PAGE, $http->requestedUrls());
    }

    /** @return iterable<string, array{string, int}> */
    public static function refusals(): iterable
    {
        yield 'unauthorized on the account' => [self::VERIFY, Response::HTTP_UNAUTHORIZED];
        yield 'forbidden on the follows' => [self::FOLLOWING, Response::HTTP_FORBIDDEN];
    }

    #[DataProvider('refusals')]
    public function testARefusedTokenIsRejected(string $route, int $status): void
    {
        $http = new RoutedHttpClient($this->routes([$route => new MockResponse('{}', ['http_code' => $status])]));

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->source($http)->candidates(SocialAccounts::mastodon());
    }

    /** @return iterable<string, array{string, MockResponse}> */
    public static function unusableAnswers(): iterable
    {
        yield 'a server error on the lists' => [
            self::LISTS,
            new MockResponse('', ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR]),
        ];
        yield 'a server error on the account despite a usable body' => [
            self::VERIFY,
            new MockResponse('{"id":"109"}', ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR]),
        ];
        yield 'an account without an id' => [self::VERIFY, new MockResponse('{"username":"alice"}')];
        yield 'a transport failure' => [self::FOLLOWING, new MockResponse('', ['error' => 'Connection refused'])];
        yield 'follows that are not a list' => [self::FOLLOWING, new MockResponse('{"error":"gone"}')];
        yield 'follows that are not JSON' => [self::FOLLOWING, new MockResponse('not json')];
        yield 'lists that are not a list' => [self::LISTS, new MockResponse('{"error":"gone"}')];
    }

    #[DataProvider('unusableAnswers')]
    public function testAnUnusableAnswerIsUnreachable(string $route, MockResponse $answer): void
    {
        $http = new RoutedHttpClient($this->routes([$route => $answer]));

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->candidates(SocialAccounts::mastodon());
    }
}
