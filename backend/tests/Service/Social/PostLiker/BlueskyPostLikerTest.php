<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostLiker;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostLiker\BlueskyPostLiker;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyPostLikerTest extends TestCase
{
    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string LIKE_URI = 'at://did:plc:alice/app.bsky.feed.like/3lk2';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string CREATE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.createRecord';
    private const string DELETE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.deleteRecord';

    public function testALikeIsALikeRecordInTheAccountsRepoNamingThePost(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => self::json(['uri' => self::LIKE_URI, 'cid' => 'bafy-like']),
        ]);

        $result = $this->liker($http)->like(self::post());

        self::assertSame(self::LIKE_URI, $result->likeId);
        self::assertSame([
            'repo' => 'did:plc:alice',
            'collection' => 'app.bsky.feed.like',
            'record' => [
                '$type' => 'app.bsky.feed.like',
                'subject' => ['uri' => self::POST_URI, 'cid' => 'bafy-post'],
                'createdAt' => '2026-10-13T06:30:00.123Z',
            ],
        ], json_decode($http->requests[1]['body'], true));
    }

    public function testTheLikeTimeIsUtcWhateverTheDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            $http = new RoutedHttpClient([
                self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
                self::CREATE_RECORD => self::json(['uri' => self::LIKE_URI]),
            ]);
            $liker = new BlueskyPostLiker(
                SocialApiWiring::blueskyRecords($http->client(), '2026-10-13T19:30:00.007+13:00'),
            );

            $liker->like(self::post());
        } finally {
            date_default_timezone_set($previous);
        }

        $record = JsonNodeModel::of(json_decode($http->requests[1]['body'], true))->node('record');
        self::assertSame('2026-10-13T06:30:00.007Z', $record->string('createdAt'));
    }

    public function testAnExistingLikeIsReusedWithoutAnyCall(): void
    {
        $http = new RoutedHttpClient([]);

        $result = $this->liker($http)->like(self::post()->alreadyLikedAs(self::LIKE_URI));

        self::assertSame(self::LIKE_URI, $result->likeId);
        self::assertSame([], $http->requests);
    }

    public function testALikeBlueskyDoesNotNameIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => self::json([]),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->liker($http)->like(self::post());
    }

    #[DataProvider('likeUrisThatAreValid')]
    public function testEveryRealLikeUriIsKept(string $uri): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => self::json(['uri' => $uri]),
        ]);

        self::assertSame($uri, $this->liker($http)->like(self::post())->likeId);
    }

    /** @return iterable<string, array{string}> */
    public static function likeUrisThatAreValid(): iterable
    {
        yield 'did:plc and a tid' => ['at://did:plc:ewvi7nxzyoun6zhxrhs64oiz/app.bsky.feed.like/3kqz2abcdef2k'];
        yield 'did:web' => ['at://did:web:example.com/app.bsky.feed.like/3lk2'];
        yield 'exactly 512 characters' => [self::likeUriOfLength(512)];
        yield 'did:web with a port' => ['at://did:web:localhost%3A3000/app.bsky.feed.like/3lk2'];
    }

    #[DataProvider('likeUrisThatAreMalformed')]
    public function testALikeUriThatIsNoLikeRecordIsAnUnreachableNetwork(string $uri): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => self::json(['uri' => $uri]),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->liker($http)->like(self::post());
    }

    /** @return iterable<string, array{string}> */
    public static function likeUrisThatAreMalformed(): iterable
    {
        yield 'no slash at all' => ['3lk2'];
        yield 'empty record key' => ['at://did:plc:alice/app.bsky.feed.like/'];
        yield 'another collection' => ['at://did:plc:alice/app.bsky.feed.post/3lk2'];
        yield 'no did' => ['at://alice.test/app.bsky.feed.like/3lk2'];
        yield 'a nested record key' => ['at://did:plc:alice/app.bsky.feed.like/3lk2/extra'];
        yield 'over 512 characters' => [self::likeUriOfLength(513)];
    }

    private static function likeUriOfLength(int $length): string
    {
        $prefix = 'at://did:plc:alice/app.bsky.feed.like/';

        return $prefix . str_repeat('a', $length - \strlen($prefix));
    }

    public function testAThrottledLikeCarriesItsDelay(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => self::throttled(),
        ]);

        try {
            $this->liker($http)->like(self::post());
            self::fail('A 429 must not read as a like.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds);
        }
    }

    public function testUnlikingDeletesTheLikeRecordByTheLastSegmentOfItsUri(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::DELETE_RECORD => self::json([]),
        ]);

        $this->liker($http)->unlike(SocialApiWiring::blueskyAccount(), self::LIKE_URI);

        self::assertSame(
            ['repo' => 'did:plc:alice', 'collection' => 'app.bsky.feed.like', 'rkey' => '3lk2'],
            json_decode($http->requests[1]['body'], true),
        );
    }

    public function testAStoredLikeThatIsNoLikeRecordIsRefusedWithoutAsking(): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->liker($http)->unlike(SocialApiWiring::blueskyAccount(), self::POST_URI);
            self::fail('Only a like record can be taken back.');
        } catch (SocialPostRefusedException) {
            self::assertSame([], $http->requests);
        }
    }

    public function testAThrottledUnlikeCarriesItsDelay(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::DELETE_RECORD => self::throttled(),
        ]);

        try {
            $this->liker($http)->unlike(SocialApiWiring::blueskyAccount(), self::LIKE_URI);
            self::fail('A 429 must not read as taken back.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds);
        }
    }

    public function testALikeBlueskyRejectsIsARefusalOfThePost(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => self::failed(Response::HTTP_BAD_REQUEST, 'InvalidRequest'),
        ]);

        $this->expectException(SocialPostRefusedException::class);

        $this->liker($http)->like(self::post());
    }

    public function testALikeWhoseSessionStaysStaleIsARefusedCredentialNotARefusedPost(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => self::failed(Response::HTTP_BAD_REQUEST, 'ExpiredToken'),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->liker($http)->like(self::post());
    }

    public function testUnlikingALikeBlueskyNoLongerHasSucceeds(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::DELETE_RECORD => self::failed(Response::HTTP_NOT_FOUND, 'RecordNotFound'),
        ]);

        $this->liker($http)->unlike(SocialApiWiring::blueskyAccount(), self::LIKE_URI);

        self::assertCount(2, $http->requests);
    }

    public function testAnUnlikeBlueskyRejectsIsARefusalOfThePost(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::DELETE_RECORD => self::failed(Response::HTTP_BAD_REQUEST, 'AccountDeactivated'),
        ]);

        $this->expectException(SocialPostRefusedException::class);

        $this->liker($http)->unlike(SocialApiWiring::blueskyAccount(), self::LIKE_URI);
    }

    public function testAnUnlikeWhoseSessionStaysStaleIsARefusedCredential(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::DELETE_RECORD => self::failed(Response::HTTP_BAD_REQUEST, 'ExpiredToken'),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->liker($http)->unlike(SocialApiWiring::blueskyAccount(), self::LIKE_URI);
    }

    public function testAnUnlikeBlueskyFailsIsAnUnreachableNetwork(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::DELETE_RECORD => self::failed(Response::HTTP_INTERNAL_SERVER_ERROR, 'InternalServerError'),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->liker($http)->unlike(SocialApiWiring::blueskyAccount(), self::LIKE_URI);
    }

    private function liker(RoutedHttpClient $http): BlueskyPostLiker
    {
        return new BlueskyPostLiker(SocialApiWiring::blueskyRecords($http->client()));
    }

    private static function post(): SocialPostModel
    {
        return SocialPostModel::onBluesky(SocialApiWiring::blueskyAccount(), self::POST_URI, 'bafy-post');
    }

    private static function throttled(): MockResponse
    {
        return new MockResponse('{}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After' => '60'],
        ]);
    }

    private static function failed(int $status, string $error): MockResponse
    {
        return new MockResponse(json_encode(['error' => $error], \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    /** @param array<mixed> $body */
    private static function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
