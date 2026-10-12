<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\NetworkVerifier;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\NetworkVerifier\BlueskyVerifier;
use App\Service\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyVerifierTest extends TestCase
{
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';

    /** @return array<string, MockResponse> */
    private function identityRoutes(): array
    {
        return [
            'GET https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=alice.bsky.social'
                => new MockResponse('{"did":"did:plc:alice"}'),
            'GET https://plc.directory/did:plc:alice' => new MockResponse(
                json_encode([
                'id' => 'did:plc:alice',
                'service' => [[
                    'id' => '#atproto_pds',
                    'type' => 'AtprotoPersonalDataServer',
                    'serviceEndpoint' => 'https://pds.example.test',
                ]],
                ], \JSON_THROW_ON_ERROR),
            ),
        ];
    }

    private function verifier(RoutedHttpClient $http): BlueskyVerifier
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
        $api = new BlueskyApiClient(FetchWiring::guardedApiClient($http->client(), $guard));

        return new BlueskyVerifier(
            new BlueskyIdentityResolver($api),
            new BlueskySessionOpener($api, new StoredCredential(SocialAccounts::cipher())),
        );
    }

    private function credentials(): SocialCredentialsModel
    {
        return SocialCredentialsModel::bluesky('@Alice.bsky.social', 'abcd-efgh-ijkl-mnop');
    }

    public function testAnAcceptedAppPasswordVerifiesTheAccountAtItsPds(): void
    {
        $http = new RoutedHttpClient($this->identityRoutes() + [
            self::SESSION => new MockResponse('{"did":"did:plc:alice","handle":"alice.bsky.social","accessJwt":"jwt"}'),
        ]);

        $account = $this->verifier($http)->verify($this->credentials());

        self::assertSame('did:plc:alice', $account->identity);
        self::assertSame('@alice.bsky.social', $account->handle);
        self::assertSame('https://pds.example.test', $account->serviceUrl);
        $session = $http->requests[2];
        self::assertSame('https://pds.example.test/xrpc/com.atproto.server.createSession', $session['url']);
        self::assertSame('{"identifier":"did:plc:alice","password":"abcd-efgh-ijkl-mnop"}', $session['body']);
    }

    public function testARefusedAppPasswordIsRejected(): void
    {
        $http = new RoutedHttpClient($this->identityRoutes() + [
            self::SESSION => new MockResponse(
                '{"error":"AuthenticationRequired"}',
                ['http_code' => Response::HTTP_UNAUTHORIZED],
            ),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->verifier($http)->verify($this->credentials());
    }

    public function testASessionForAnotherDidIsRejected(): void
    {
        $http = new RoutedHttpClient($this->identityRoutes() + [
            self::SESSION => new MockResponse(
                '{"did":"did:plc:mallory","handle":"mallory.bsky.social","accessJwt":"jwt"}',
            ),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->verifier($http)->verify($this->credentials());
    }

    public function testTheNetworkKeyIsBluesky(): void
    {
        self::assertSame('bluesky', BlueskyVerifier::network());
    }

    public function testAnOutageOnTheSessionCallIsUnreachable(): void
    {
        $http = new RoutedHttpClient($this->identityRoutes() + [
            self::SESSION => new MockResponse('', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->verifier($http)->verify($this->credentials());
    }

    public function testAMalformedSessionAnswerIsUnreachable(): void
    {
        $http = new RoutedHttpClient($this->identityRoutes() + [
            self::SESSION => new MockResponse('<html>'),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->verifier($http)->verify($this->credentials());
    }

    public function testAPlainHttpPdsIsUnresolvableAndNeverReceivesThePassword(): void
    {
        $routes = $this->identityRoutes();
        $routes['GET https://plc.directory/did:plc:alice'] = new MockResponse(
            $this->documentWithEndpoint('http://pds.example.test'),
        );
        $http = new RoutedHttpClient($routes);

        try {
            $this->verifier($http)->verify($this->credentials());
            self::fail('A plain http PDS must be refused.');
        } catch (SocialAccountUnresolvableException) {
            self::assertNotContains('POST', array_column($http->requests, 'method'));
        }
    }

    public function testATrailingSlashEndpointCreatesTheSessionWithoutADoubleSlash(): void
    {
        $routes = $this->identityRoutes();
        $routes['GET https://plc.directory/did:plc:alice'] = new MockResponse(
            $this->documentWithEndpoint('https://PDS.example.test/'),
        );
        $routes[self::SESSION] = new MockResponse(
            '{"did":"did:plc:alice","handle":"alice.bsky.social","accessJwt":"jwt"}',
        );
        $http = new RoutedHttpClient($routes);

        $account = $this->verifier($http)->verify($this->credentials());

        self::assertSame('https://pds.example.test', $account->serviceUrl);
        self::assertSame('https://pds.example.test/xrpc/com.atproto.server.createSession', $http->requests[2]['url']);
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
