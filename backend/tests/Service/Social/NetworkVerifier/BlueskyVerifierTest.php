<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\NetworkVerifier;

use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\NetworkVerifier\BlueskyVerifier;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

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
        $api = new GuardedApiClient($http->client(), $guard, 'TestAgent/1.0');

        return new BlueskyVerifier(new BlueskyIdentityResolver($api), $api);
    }

    private function credentials(): SocialCredentialsModel
    {
        return SocialCredentialsModel::bluesky('@Alice.bsky.social', 'abcd-efgh-ijkl-mnop');
    }

    public function testAnAcceptedAppPasswordVerifiesTheAccountAtItsPds(): void
    {
        $http = new RoutedHttpClient($this->identityRoutes() + [
            self::SESSION => new MockResponse('{"did":"did:plc:alice","handle":"alice.bsky.social"}'),
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
            self::SESSION => new MockResponse('{"error":"AuthenticationRequired"}', ['http_code' => 401]),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->verifier($http)->verify($this->credentials());
    }

    public function testASessionForAnotherDidIsRejected(): void
    {
        $http = new RoutedHttpClient($this->identityRoutes() + [
            self::SESSION => new MockResponse('{"did":"did:plc:mallory","handle":"mallory.bsky.social"}'),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->verifier($http)->verify($this->credentials());
    }

    public function testTheNetworkKeyIsBluesky(): void
    {
        self::assertSame('bluesky', BlueskyVerifier::network());
    }
}
