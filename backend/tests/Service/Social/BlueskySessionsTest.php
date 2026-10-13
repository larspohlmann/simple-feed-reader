<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\BlueskySessionCache;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskySessionsTest extends TestCase
{
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string CREATE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.createRecord';
    private const string CREATE_RECORD_PATH = '/xrpc/com.atproto.repo.createRecord';
    private const string LIKE_URI = '{"uri":"at://did:plc:alice/app.bsky.feed.like/3l"}';

    public function testASecondPollReusesTheCachedSession(): void
    {
        $http = new RoutedHttpClient([self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1')]);
        $sessions = SocialApiWiring::blueskySessions($http->client());
        $account = SocialApiWiring::blueskyAccount();

        $first = $sessions->current($account);
        $second = $sessions->current($account);

        self::assertSame('jwt-1', $first->accessJwt);
        self::assertEquals($first, $second);
        self::assertCount(1, $http->requests);
    }

    public function testAFreshSessionReplacesTheCachedOne(): void
    {
        $cache = new ArrayAdapter();
        $account = SocialApiWiring::blueskyAccount();
        SocialApiWiring::blueskySessionCache($cache)->remember(
            $account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social'),
        );
        $http = new RoutedHttpClient([self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-2')]);
        $sessions = SocialApiWiring::blueskySessions($http->client(), $cache);

        self::assertSame('stale-jwt', $sessions->current($account)->accessJwt);
        self::assertSame('jwt-2', $sessions->fresh($account)->accessJwt);
        self::assertSame('jwt-2', $sessions->current($account)->accessJwt);
        self::assertCount(1, $http->requests);
    }

    public function testAFailedRefreshLeavesNoStaleSessionBehind(): void
    {
        $cache = new ArrayAdapter();
        $account = SocialApiWiring::blueskyAccount();
        SocialApiWiring::blueskySessionCache($cache)->remember(
            $account,
            new BlueskySessionModel('stale-jwt', 'alice.bsky.social'),
        );
        $http = new RoutedHttpClient([self::SESSION => new MockResponse('', [
            'http_code' => Response::HTTP_SERVICE_UNAVAILABLE,
        ])]);

        try {
            SocialApiWiring::blueskySessions($http->client(), $cache)->fresh($account);
            self::fail('An outage must not open a session.');
        } catch (SocialNetworkUnreachableException) {
            self::assertNull(SocialApiWiring::blueskySessionCache($cache)->find($account));
        }
    }

    public function testTheCacheHoldsTheTokenSealedNeverInTheClear(): void
    {
        $cache = new ArrayAdapter();
        $http = new RoutedHttpClient([self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-secret-token')]);

        SocialApiWiring::blueskySessions($http->client(), $cache)->current(SocialApiWiring::blueskyAccount());

        self::assertNotSame([], $cache->getValues());
        self::assertStringNotContainsString('jwt-secret-token', serialize($cache->getValues()));
    }

    public function testANewCredentialMissesTheSessionTheOldOneOpened(): void
    {
        $cache = new ArrayAdapter();
        $account = SocialApiWiring::blueskyAccount();
        SocialApiWiring::blueskySessionCache($cache)->remember(
            $account,
            new BlueskySessionModel('old-jwt', 'alice.bsky.social'),
        );
        $account->replaceCredential(
            '@alice.bsky.social',
            SocialAccounts::cipher()->seal(SocialAccounts::OWNER_ID, 'wxyz-wxyz-wxyz-9999'),
            '9999',
            new \DateTimeImmutable('2026-10-12 11:00:00'),
        );
        $http = new RoutedHttpClient([self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-new')]);

        $sessions = SocialApiWiring::blueskySessions($http->client(), $cache);

        self::assertSame('jwt-new', $sessions->current($account)->accessJwt);
    }

    public function testASessionSealedUnderAnotherKeyOpensAFreshOne(): void
    {
        $cache = new ArrayAdapter();
        $account = SocialApiWiring::blueskyAccount();
        $rotatedCipher = new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('r', 32)));
        (new BlueskySessionCache($cache, $rotatedCipher))->remember(
            $account,
            new BlueskySessionModel('unreadable-jwt', 'alice.bsky.social'),
        );
        $http = new RoutedHttpClient([self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-fresh')]);

        $sessions = SocialApiWiring::blueskySessions($http->client(), $cache);

        self::assertSame('jwt-fresh', $sessions->current($account)->accessJwt);
        self::assertCount(1, $http->requests);
    }

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
}
