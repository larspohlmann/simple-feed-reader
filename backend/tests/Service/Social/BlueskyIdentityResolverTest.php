<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyIdentityResolverTest extends TestCase
{
    private const string RESOLVE_ALICE = 'GET https://public.api.bsky.app/xrpc/'
        . 'com.atproto.identity.resolveHandle?handle=alice.bsky.social';

    private function resolver(RoutedHttpClient $http): BlueskyIdentityResolver
    {
        $address = ['93.184.216.34'];
        $guard = new UrlGuard(
            new StaticDnsResolver([
                'public.api.bsky.app' => $address,
                'plc.directory' => $address,
                'alice.example.com' => $address,
                'pds.example.test' => $address,
            ]),
            new IpValidator(),
        );

        return new BlueskyIdentityResolver(FetchWiring::guardedApiClient($http->client(), $guard));
    }

    public function testAHandleResolvesThroughPlcToItsPds(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_ALICE => new MockResponse('{"did":"did:plc:alice"}'),
            'GET https://plc.directory/did:plc:alice' => new MockResponse(
                '{"id":"did:plc:alice","service":[{"id":"#atproto_pds",'
                . '"type":"AtprotoPersonalDataServer","serviceEndpoint":"https://pds.example.test"}]}',
            ),
        ]);

        $identity = $this->resolver($http)->resolve('alice.bsky.social');

        self::assertSame('did:plc:alice', $identity->did);
        self::assertSame('https://pds.example.test', $identity->pdsUrl);
        self::assertSame(
            [
                'https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=alice.bsky.social',
                'https://plc.directory/did:plc:alice',
            ],
            $http->requestedUrls(),
        );
    }

    public function testADidWebResolvesThroughItsWellKnownDocument(): void
    {
        $http = new RoutedHttpClient([
            'GET https://alice.example.com/.well-known/did.json' => new MockResponse(
                '{"id":"did:web:alice.example.com","service":[{"id":"did:web:alice.example.com#atproto_pds",'
                . '"type":"AtprotoPersonalDataServer","serviceEndpoint":"https://pds.example.test"}]}',
            ),
        ]);

        $identity = $this->resolver($http)->resolve('did:web:alice.example.com');

        self::assertSame('did:web:alice.example.com', $identity->did);
        self::assertSame('https://pds.example.test', $identity->pdsUrl);
        self::assertSame(['https://alice.example.com/.well-known/did.json'], $http->requestedUrls());
    }

    public function testAnUnknownHandleIsUnresolvable(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_ALICE => new MockResponse(
                '{"error":"InvalidRequest"}',
                ['http_code' => Response::HTTP_BAD_REQUEST],
            ),
        ]);

        $this->expectException(SocialAccountUnresolvableException::class);

        $this->resolver($http)->resolve('alice.bsky.social');
    }

    public function testADocumentWithoutAPdsIsUnresolvable(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_ALICE => new MockResponse('{"did":"did:plc:alice"}'),
            'GET https://plc.directory/did:plc:alice' => new MockResponse('{"service":[]}'),
        ]);

        $this->expectException(SocialAccountUnresolvableException::class);

        $this->resolver($http)->resolve('alice.bsky.social');
    }

    public function testATransportFailureIsUnreachable(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_ALICE => new MockResponse('', ['error' => 'connection refused']),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->resolver($http)->resolve('alice.bsky.social');
    }

    public function testAMissingDidDocumentIsUnresolvable(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_ALICE => new MockResponse('{"did":"did:plc:alice"}'),
            'GET https://plc.directory/did:plc:alice' => new MockResponse(
                'Not Found',
                ['http_code' => Response::HTTP_NOT_FOUND],
            ),
        ]);

        $this->expectException(SocialAccountUnresolvableException::class);

        $this->resolver($http)->resolve('alice.bsky.social');
    }

    public function testAnOutageOnTheHandleLookupIsUnreachable(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_ALICE => new MockResponse('', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->resolver($http)->resolve('alice.bsky.social');
    }

    public function testAnOutageOnTheDidDocumentIsUnreachable(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_ALICE => new MockResponse('{"did":"did:plc:alice"}'),
            'GET https://plc.directory/did:plc:alice' => new MockResponse(
                '',
                ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE],
            ),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->resolver($http)->resolve('alice.bsky.social');
    }

    public function testAMalformedHandleAnswerIsUnreachable(): void
    {
        $http = new RoutedHttpClient([self::RESOLVE_ALICE => new MockResponse('<html>')]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->resolver($http)->resolve('alice.bsky.social');
    }

    /** @return iterable<string, array{string}> */
    public static function unusableEndpoints(): iterable
    {
        yield 'plain http' => ['http://pds.example.test'];
        yield 'no host' => ['https:///x'];
        yield 'not a url' => ['not a url'];
    }

    #[DataProvider('unusableEndpoints')]
    public function testAnUnusableEndpointIsUnresolvable(string $endpoint): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_ALICE => new MockResponse('{"did":"did:plc:alice"}'),
            'GET https://plc.directory/did:plc:alice' => new MockResponse($this->documentWithEndpoint($endpoint)),
        ]);

        $this->expectException(SocialAccountUnresolvableException::class);
        $this->expectExceptionMessage('is not reachable over https');

        $this->resolver($http)->resolve('alice.bsky.social');
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedWebDids(): iterable
    {
        yield 'path style' => ['did:web:alice.example.com:user:alice'];
        yield 'encoded port' => ['did:web:alice.example.com%3A8443'];
        yield 'encoded port, lowercase' => ['did:web:alice.example.com%3a8443'];
    }

    #[DataProvider('unsupportedWebDids')]
    public function testAWebDidWithAPathOrAPortIsUnsupported(string $did): void
    {
        $http = new RoutedHttpClient([]);

        try {
            $this->resolver($http)->resolve($did);
            self::fail('Expected SocialAccountUnresolvableException');
        } catch (SocialAccountUnresolvableException $exception) {
            self::assertStringContainsString('not supported', $exception->getMessage());
            self::assertSame([], $http->requestedUrls());
        }
    }

    public function testAnEndpointIsReducedToItsOrigin(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_ALICE => new MockResponse('{"did":"did:plc:alice"}'),
            'GET https://plc.directory/did:plc:alice' => new MockResponse(
                $this->documentWithEndpoint('https://PDS.example.test:8443/some/path?x=1'),
            ),
        ]);

        $identity = $this->resolver($http)->resolve('alice.bsky.social');

        self::assertSame('https://pds.example.test:8443', $identity->pdsUrl);
    }

    private function documentWithEndpoint(string $endpoint): string
    {
        return json_encode([
            'id' => 'did:plc:alice',
            'service' => [[
                'id' => '#atproto_pds',
                'type' => 'AtprotoPersonalDataServer',
                'serviceEndpoint' => $endpoint,
            ]],
        ], \JSON_THROW_ON_ERROR);
    }
}
