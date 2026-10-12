<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\NetworkVerifier;

use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\NetworkVerifier\MastodonVerifier;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonVerifierTest extends TestCase
{
    private const string VERIFY = 'GET https://mastodon.example.test/api/v1/accounts/verify_credentials';

    private function verifier(RoutedHttpClient $http): MastodonVerifier
    {
        $guard = new UrlGuard(
            new StaticDnsResolver([
                'mastodon.example.test' => ['93.184.216.34'],
                'social.example.test' => ['93.184.216.34'],
            ]),
            new IpValidator(),
        );

        return new MastodonVerifier(new GuardedApiClient($http->client(), $guard, 'TestAgent/1.0'));
    }

    private function credentials(): SocialCredentialsModel
    {
        return SocialCredentialsModel::mastodon('mastodon.example.test', 'token-1234');
    }

    public function testAnAcceptedTokenVerifiesTheAccount(): void
    {
        $http = new RoutedHttpClient([
            self::VERIFY => new MockResponse(
                '{"id":"1","username":"lars","acct":"lars","url":"https://mastodon.example.test/@lars"}',
            ),
        ]);

        $account = $this->verifier($http)->verify($this->credentials());

        self::assertSame('lars@mastodon.example.test', $account->identity);
        self::assertSame('@lars@mastodon.example.test', $account->handle);
        self::assertSame('https://mastodon.example.test', $account->serviceUrl);
        self::assertSame(['https://mastodon.example.test/api/v1/accounts/verify_credentials'], $http->requestedUrls());
        self::assertContains('Authorization: Bearer token-1234', $http->requests[0]['headers']);
    }

    public function testARefusedTokenIsRejected(): void
    {
        $http = new RoutedHttpClient([
            self::VERIFY => new MockResponse('{"error":"invalid"}', ['http_code' => Response::HTTP_UNAUTHORIZED]),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->verifier($http)->verify($this->credentials());
    }

    public function testAForbiddenTokenIsRejected(): void
    {
        $http = new RoutedHttpClient([
            self::VERIFY => new MockResponse('{"error":"scope"}', ['http_code' => Response::HTTP_FORBIDDEN]),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->verifier($http)->verify($this->credentials());
    }

    public function testAnUnexpectedAnswerIsUnreachable(): void
    {
        $http = new RoutedHttpClient([self::VERIFY => new MockResponse('<html>')]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->verifier($http)->verify($this->credentials());
    }

    public function testATransportFailureIsUnreachable(): void
    {
        $http = new RoutedHttpClient([self::VERIFY => new MockResponse('', ['error' => 'connection refused'])]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->verifier($http)->verify($this->credentials());
    }

    public function testTheNetworkKeyIsMastodon(): void
    {
        self::assertSame('mastodon', MastodonVerifier::network());
    }

    public function testANonDefaultPortStaysInTheIdentityAndHandle(): void
    {
        $http = new RoutedHttpClient([
            'GET https://social.example.test:8443/api/v1/accounts/verify_credentials' => new MockResponse(
                '{"id":"1","username":"lars"}',
            ),
        ]);

        $credentials = SocialCredentialsModel::mastodon('social.example.test:8443', 'token-1234');
        $account = $this->verifier($http)->verify($credentials);

        self::assertSame('lars@social.example.test:8443', $account->identity);
        self::assertSame('@lars@social.example.test:8443', $account->handle);
        self::assertSame('https://social.example.test:8443', $account->serviceUrl);
    }
}
