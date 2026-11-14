<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\HomeServerCheck;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HomeServerCheck\BlueskyHomeServerCheck;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyHomeServerCheckTest extends TestCase
{
    private const string POST = 'at://did:plc:alice/app.bsky.feed.post/3kpost';
    private const string DID_DOCUMENT = 'GET https://plc.directory/did:plc:alice';
    private const string GET_RECORD = 'GET https://pds.example.test/xrpc/com.atproto.repo.getRecord'
        . '?repo=did%3Aplc%3Aalice&collection=app.bsky.feed.post&rkey=3kpost';
    private const string RESOLVE_ALICE = 'GET https://public.api.bsky.app/xrpc/'
        . 'com.atproto.identity.resolveHandle?handle=alice.bsky.social';

    public function testARecordTheAuthorsPdsCannotFindIsConfirmedDeletedWithoutAToken(): void
    {
        $http = new RoutedHttpClient([
            self::DID_DOCUMENT => self::didDocument(),
            self::GET_RECORD => self::recordError(Response::HTTP_BAD_REQUEST, 'RecordNotFound'),
        ]);

        self::assertTrue($this->check($http)->confirmsDeleted(self::POST));
        self::assertSame([self::DID_DOCUMENT, self::GET_RECORD], $http->requestLines());
        self::assertSame([], preg_grep('/^Authorization:/i', $http->requests[1]['headers']));
    }

    public function testAPostNamedByHandleProvesNothingAndAsksNothing(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_ALICE => new MockResponse('{"did":"did:plc:alice"}'),
            self::DID_DOCUMENT => self::didDocument(),
            self::GET_RECORD => self::recordError(Response::HTTP_BAD_REQUEST, 'RecordNotFound'),
        ]);

        self::assertFalse(
            $this->check($http)->confirmsDeleted('at://alice.bsky.social/app.bsky.feed.post/3kpost'),
        );
        self::assertSame([], $http->requests);
    }

    #[DataProvider('unprovenAnswers')]
    public function testAnyOtherAnswerProvesNothing(MockResponse $answer): void
    {
        $http = new RoutedHttpClient([self::DID_DOCUMENT => self::didDocument(), self::GET_RECORD => $answer]);

        self::assertFalse($this->check($http)->confirmsDeleted(self::POST));
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function unprovenAnswers(): iterable
    {
        yield 'still there' => [new MockResponse('{"uri":"' . self::POST . '","value":{}}')];
        yield 'a taken-down repo' => [self::recordError(Response::HTTP_BAD_REQUEST, 'RepoTakendown')];
        yield 'not found without its error' => [self::recordError(Response::HTTP_NOT_FOUND, 'NotFound')];
        yield 'failing' => [self::recordError(Response::HTTP_INTERNAL_SERVER_ERROR, 'InternalServerError')];
        yield 'redirected' => [new MockResponse('', [
            'http_code' => Response::HTTP_FOUND,
            'response_headers' => ['Location: https://elsewhere.example/xrpc/com.atproto.repo.getRecord'],
        ])];
        yield 'not json' => [new MockResponse('RecordNotFound', ['http_code' => Response::HTTP_BAD_REQUEST])];
        yield 'unauthorized' => [self::recordError(Response::HTTP_UNAUTHORIZED, 'RecordNotFound')];
        yield 'throttled' => [self::recordError(Response::HTTP_TOO_MANY_REQUESTS, 'RecordNotFound')];
    }

    public function testAnAuthorWithoutADidDocumentProvesNothing(): void
    {
        $http = new RoutedHttpClient([
            self::DID_DOCUMENT => new MockResponse('Not Found', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);

        self::assertFalse($this->check($http)->confirmsDeleted(self::POST));
        self::assertSame([self::DID_DOCUMENT], $http->requestLines());
    }

    public function testAnAddressThatNamesNoPostProvesNothingAndAsksNothing(): void
    {
        $http = new RoutedHttpClient([]);

        self::assertFalse($this->check($http)->confirmsDeleted('at://did:plc:alice/app.bsky.feed.like/3klike'));
        self::assertSame([], $http->requests);
    }

    public function testAPdsThatDoesNotAnswerIsUnreachable(): void
    {
        $http = new RoutedHttpClient([
            self::DID_DOCUMENT => self::didDocument(),
            self::GET_RECORD => new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->check($http)->confirmsDeleted(self::POST);
    }

    private function check(RoutedHttpClient $http): BlueskyHomeServerCheck
    {
        $address = ['93.184.216.34'];
        $guard = new UrlGuard(
            new StaticDnsResolver([
                'public.api.bsky.app' => $address,
                'plc.directory' => $address,
                'pds.example.test' => $address,
            ]),
            new IpValidator(),
        );
        $bluesky = new BlueskyApiClient(FetchWiring::guardedApiClient($http->client(), $guard));

        return new BlueskyHomeServerCheck($bluesky, new BlueskyIdentityResolver($bluesky));
    }

    private static function didDocument(): MockResponse
    {
        return new MockResponse(
            '{"id":"did:plc:alice","service":[{"id":"#atproto_pds",'
            . '"type":"AtprotoPersonalDataServer","serviceEndpoint":"https://pds.example.test"}]}',
        );
    }

    private static function recordError(int $status, string $error): MockResponse
    {
        return new MockResponse(
            json_encode(['error' => $error, 'message' => 'x'], \JSON_THROW_ON_ERROR),
            ['http_code' => $status],
        );
    }
}
