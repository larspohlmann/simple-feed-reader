<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

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

        return new BlueskyIdentityResolver(new GuardedApiClient($http->client(), $guard, 'TestAgent/1.0'));
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
            self::RESOLVE_ALICE => new MockResponse('{"error":"InvalidRequest"}', ['http_code' => 400]),
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
}
