<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\StoredCredential;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskySessionsTest extends TestCase
{
    use AssignsEntityIds;

    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';

    public function testASecondPollReusesTheCachedSession(): void
    {
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-1')]);
        $sessions = $this->sessions($http, new ArrayAdapter());
        $account = self::account();

        $first = $sessions->current($account);
        $second = $sessions->current($account);

        self::assertSame('jwt-1', $first->accessJwt);
        self::assertEquals($first, $second);
        self::assertCount(1, $http->requests);
    }

    public function testAFreshSessionReplacesTheCachedOne(): void
    {
        $cache = new ArrayAdapter();
        $account = self::account();
        self::cacheOver($cache)->remember(
            $account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social'),
        );
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-2')]);
        $sessions = $this->sessions($http, $cache);

        self::assertSame('stale-jwt', $sessions->current($account)->accessJwt);
        self::assertSame('jwt-2', $sessions->fresh($account)->accessJwt);
        self::assertSame('jwt-2', $sessions->current($account)->accessJwt);
        self::assertCount(1, $http->requests);
    }

    public function testAFailedRefreshLeavesNoStaleSessionBehind(): void
    {
        $cache = new ArrayAdapter();
        $account = self::account();
        self::cacheOver($cache)->remember(
            $account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social'),
        );
        $http = new RoutedHttpClient([self::SESSION => new MockResponse('', [
            'http_code' => Response::HTTP_SERVICE_UNAVAILABLE,
        ])]);

        try {
            $this->sessions($http, $cache)->fresh($account);
            self::fail('An outage must not open a session.');
        } catch (SocialNetworkUnreachableException) {
            self::assertNull(self::cacheOver($cache)->find($account));
        }
    }

    public function testTheCacheHoldsTheTokenSealedNeverInTheClear(): void
    {
        $cache = new ArrayAdapter();
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-secret-token')]);

        $this->sessions($http, $cache)->current(self::account());

        self::assertNotSame([], $cache->getValues());
        self::assertStringNotContainsString('jwt-secret-token', serialize($cache->getValues()));
    }

    public function testANewCredentialMissesTheSessionTheOldOneOpened(): void
    {
        $cache = new ArrayAdapter();
        $account = self::account();
        self::cacheOver($cache)->remember(
            $account,
            new BlueskySessionModel('old-jwt', 'alice.bsky.social'),
        );
        $account->replaceCredential(
            '@alice.bsky.social',
            SocialAccounts::cipher()->seal(SocialAccounts::OWNER_ID, 'wxyz-wxyz-wxyz-9999'),
            '9999',
            new \DateTimeImmutable('2026-10-12 11:00:00'),
        );
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-new')]);

        self::assertSame('jwt-new', $this->sessions($http, $cache)->current($account)->accessJwt);
    }

    public function testASessionSealedUnderAnotherKeyOpensAFreshOne(): void
    {
        $cache = new ArrayAdapter();
        $account = self::account();
        $rotatedCipher = new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('r', 32)));
        (new BlueskySessionCache($cache, $rotatedCipher))->remember(
            $account,
            new BlueskySessionModel('unreadable-jwt', 'alice.bsky.social'),
        );
        $http = new RoutedHttpClient([self::SESSION => self::sessionAnswer('jwt-fresh')]);

        self::assertSame('jwt-fresh', $this->sessions($http, $cache)->current($account)->accessJwt);
        self::assertCount(1, $http->requests);
    }

    private const string CREATE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.createRecord';
    private const string CREATE_RECORD_PATH = '/xrpc/com.atproto.repo.createRecord';
    private const string LIKE_URI = '{"uri":"at://did:plc:alice/app.bsky.feed.like/3l"}';

    public function testAPostCarriesTheSessionsTokenAndItsJson(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => new MockResponse(self::LIKE_URI),
        ]);

        $answer = SocialApiWiring::blueskySessions($http->client())->postAs(
            SocialApiWiring::blueskyAccount(),
            self::CREATE_RECORD_PATH,
            ['repo' => 'did:plc:alice'],
        );

        self::assertSame(self::LIKE_URI, $answer->body);
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[1]['headers']);
        self::assertSame(['repo' => 'did:plc:alice'], json_decode($http->requests[1]['body'], true));
    }

    public function testAPostWithAnExpiredTokenIsSentOnceMoreWithAFreshSession(): void
    {
        $cache = new ArrayAdapter();
        $account = SocialApiWiring::blueskyAccount();
        SocialApiWiring::blueskySessionCache($cache)->remember(
            $account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social'),
        );
        $created = new MockResponse(self::LIKE_URI);
        $http = new MockHttpClient([
            new MockResponse('{"error":"ExpiredToken"}', ['http_code' => Response::HTTP_BAD_REQUEST]),
            SocialApiWiring::blueskySessionAnswer('jwt-2'),
            $created,
        ]);

        $answer = SocialApiWiring::blueskySessions($http, $cache)->postAs($account, self::CREATE_RECORD_PATH, []);

        self::assertTrue($answer->isSuccessful());
        $headers = $created->getRequestOptions()['headers'] ?? null;
        self::assertIsArray($headers);
        self::assertContains('Authorization: Bearer jwt-2', $headers);
        self::assertSame(3, $http->getRequestsCount());
    }

    public function testAPostRefusedAsWrongCredentialIsNeverRetried(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => new MockResponse('{"error":"AuthRequired"}', [
                'http_code' => Response::HTTP_UNAUTHORIZED,
            ]),
        ]);

        $answer = SocialApiWiring::blueskySessions($http->client())
            ->postAs(SocialApiWiring::blueskyAccount(), self::CREATE_RECORD_PATH, []);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $answer->status);
        self::assertCount(2, $http->requests);
    }

    public function testAPostStaleAfterTheRetryIsReturnedWithoutAThirdAttempt(): void
    {
        $stale = static fn (): MockResponse => new MockResponse('{"error":"InvalidToken"}', [
            'http_code' => Response::HTTP_BAD_REQUEST,
        ]);
        $http = new MockHttpClient([
            SocialApiWiring::blueskySessionAnswer('jwt-1'),
            $stale(),
            SocialApiWiring::blueskySessionAnswer('jwt-2'),
            $stale(),
        ]);

        $answer = SocialApiWiring::blueskySessions($http)
            ->postAs(SocialApiWiring::blueskyAccount(), self::CREATE_RECORD_PATH, []);

        self::assertTrue(BlueskySessions::isStale($answer));
        self::assertSame(4, $http->getRequestsCount());
    }

    #[DataProvider('refusedSessions')]
    public function testARefusedSessionIsARefusedCredential(ApiResponseModel $answer): void
    {
        $this->expectException(SocialCredentialsRejectedException::class);

        BlueskySessions::accepted($answer);
    }

    /** @return iterable<string, array{ApiResponseModel}> */
    public static function refusedSessions(): iterable
    {
        yield 'unauthorised' => [new ApiResponseModel(Response::HTTP_UNAUTHORIZED, '{"error":"AuthRequired"}')];
        yield 'expired token' => [new ApiResponseModel(Response::HTTP_BAD_REQUEST, '{"error":"ExpiredToken"}')];
    }

    public function testAnotherBadRequestIsAnUnreachableNetworkAndASuccessIsAccepted(): void
    {
        $success = new ApiResponseModel(Response::HTTP_OK, '{}');
        self::assertSame($success, BlueskySessions::accepted($success));

        $this->expectException(SocialNetworkUnreachableException::class);

        BlueskySessions::accepted(new ApiResponseModel(Response::HTTP_BAD_REQUEST, '{"error":"InvalidRequest"}'));
    }

    private function sessions(RoutedHttpClient $http, ArrayAdapter $cache): BlueskySessions
    {
        $guard = new UrlGuard(new StaticDnsResolver(['pds.example.test' => ['93.184.216.34']]), new IpValidator());

        $api = new BlueskyApiClient(FetchWiring::guardedApiClient($http->client(), $guard));

        return new BlueskySessions(
            $api,
            new BlueskySessionOpener($api, new StoredCredential(SocialAccounts::cipher())),
            self::cacheOver($cache),
        );
    }

    private static function cacheOver(ArrayAdapter $cache): BlueskySessionCache
    {
        return new BlueskySessionCache($cache, new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('k', 32))));
    }

    private static function account(): SocialAccount
    {
        $account = SocialAccounts::bluesky();
        self::assignId($account, 3);

        return $account;
    }

    private static function sessionAnswer(string $accessJwt): MockResponse
    {
        return new MockResponse(json_encode(
            ['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => $accessJwt],
            \JSON_THROW_ON_ERROR,
        ));
    }
}
