<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\PostReposter;

use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostReposter\BlueskyPostReposter;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyPostReposterTest extends TestCase
{
    private const string POST_URI = 'at://did:plc:bob/app.bsky.feed.post/3abc';
    private const string REPOST_URI = 'at://did:plc:alice/app.bsky.feed.repost/3rp1';
    private const string LIKE_URI = 'at://did:plc:alice/app.bsky.feed.like/3lk2';
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string CREATE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.createRecord';
    private const string DELETE_RECORD = 'POST https://pds.example.test/xrpc/com.atproto.repo.deleteRecord';

    public function testARepostIsARepostRecordInTheAccountsRepoNamingThePost(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => self::json(['uri' => self::REPOST_URI, 'cid' => 'bafy-repost']),
        ]);

        $this->reposter($http)->repost(self::post());

        self::assertSame([
            'repo' => 'did:plc:alice',
            'collection' => 'app.bsky.feed.repost',
            'record' => [
                '$type' => 'app.bsky.feed.repost',
                'subject' => ['uri' => self::POST_URI, 'cid' => 'bafy-post'],
                'createdAt' => '2026-10-13T06:30:00.123Z',
            ],
        ], json_decode($http->requests[1]['body'], true));
    }

    public function testARepostBlueskyRejectsIsARefusalOfThePost(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => self::failed(Response::HTTP_BAD_REQUEST, 'InvalidRequest'),
        ]);

        $this->expectException(SocialPostRefusedException::class);

        $this->reposter($http)->repost(self::post());
    }

    public function testAThrottledRepostCarriesItsDelay(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::CREATE_RECORD => new MockResponse('{}', [
                'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
                'response_headers' => ['Retry-After' => '60'],
            ]),
        ]);

        try {
            $this->reposter($http)->repost(self::post());
            self::fail('A 429 must not read as a repost.');
        } catch (SocialNetworkThrottledException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds);
        }
    }

    public function testUndoingARepostDeletesItsRecordByTheLastSegmentOfItsUri(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::DELETE_RECORD => self::json([]),
        ]);

        $this->reposter($http)->unrepost(SocialApiWiring::blueskyAccount(), self::REPOST_URI);

        self::assertSame(
            ['repo' => 'did:plc:alice', 'collection' => 'app.bsky.feed.repost', 'rkey' => '3rp1'],
            json_decode($http->requests[1]['body'], true),
        );
    }

    public function testUndoingARepostBlueskyNoLongerHasSucceeds(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::DELETE_RECORD => self::failed(Response::HTTP_NOT_FOUND, 'RecordNotFound'),
        ]);

        $this->reposter($http)->unrepost(SocialApiWiring::blueskyAccount(), self::REPOST_URI);

        self::assertCount(2, $http->requests);
    }

    public function testARepostIdThatIsNoRepostRecordIsRefusedWithoutAsking(): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->reposter($http)->unrepost(SocialApiWiring::blueskyAccount(), self::LIKE_URI);
            self::fail('Only a repost record can be undone.');
        } catch (SocialPostRefusedException) {
            self::assertSame([], $http->requests);
        }
    }

    public function testAnUndoWhoseSessionStaysStaleIsARefusedCredential(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::DELETE_RECORD => self::failed(Response::HTTP_BAD_REQUEST, 'ExpiredToken'),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->reposter($http)->unrepost(SocialApiWiring::blueskyAccount(), self::REPOST_URI);
    }

    private function reposter(RoutedHttpClient $http): BlueskyPostReposter
    {
        return new BlueskyPostReposter(SocialApiWiring::blueskyRecords($http->client()));
    }

    private static function post(): SocialPostModel
    {
        return SocialPostModel::onBluesky(SocialApiWiring::blueskyAccount(), self::POST_URI, 'bafy-post');
    }

    private static function failed(int $status, string $error): MockResponse
    {
        return new MockResponse(json_encode(['error' => $error], \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
