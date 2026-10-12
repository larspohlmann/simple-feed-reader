<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskySessionOpenerTest extends TestCase
{
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';

    private function opener(RoutedHttpClient $http): BlueskySessionOpener
    {
        $guard = new UrlGuard(new StaticDnsResolver(['pds.example.test' => ['93.184.216.34']]), new IpValidator());

        return new BlueskySessionOpener(
            FetchWiring::guardedApiClient($http->client(), $guard),
            new StoredCredential(SocialAccounts::cipher()),
        );
    }

    public function testTheStoredAppPasswordOpensASessionAtTheAccountsPds(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => new MockResponse(
                '{"did":"did:plc:alice","handle":"alice.bsky.social","accessJwt":"jwt-1"}',
            ),
        ]);

        $session = $this->opener($http)->openForAccount(SocialAccounts::bluesky());

        self::assertSame('jwt-1', $session->accessJwt);
        self::assertSame('did:plc:alice', $session->did);
        self::assertSame('alice.bsky.social', $session->handle);
        self::assertSame('https://pds.example.test', $session->pdsUrl);
        self::assertSame('{"identifier":"did:plc:alice","password":"abcd-efgh-ijkl-mnop"}', $http->requests[0]['body']);
    }

    public function testARefusedAppPasswordIsRejected(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => new MockResponse(
                '{"error":"AuthenticationRequired"}',
                ['http_code' => Response::HTTP_UNAUTHORIZED],
            ),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->opener($http)->openForAccount(SocialAccounts::bluesky());
    }

    public function testASessionForAnotherDidIsRejected(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => new MockResponse(
                '{"did":"did:plc:mallory","handle":"mallory.bsky.social","accessJwt":"jwt"}',
            ),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->opener($http)->openForAccount(SocialAccounts::bluesky());
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function unusableAnswers(): iterable
    {
        yield 'an outage' => [new MockResponse('', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE])];
        yield 'a session without a token' => [new MockResponse('{"did":"did:plc:alice","handle":"alice.bsky.social"}')];
        yield 'a session without a handle' => [new MockResponse('{"did":"did:plc:alice","accessJwt":"jwt"}')];
        yield 'a session without a did' => [new MockResponse('{"handle":"alice.bsky.social","accessJwt":"jwt"}')];
        yield 'a transport failure' => [new MockResponse('', ['error' => 'Connection refused'])];
    }

    #[DataProvider('unusableAnswers')]
    public function testAnUnusableAnswerIsUnreachable(MockResponse $answer): void
    {
        $http = new RoutedHttpClient([self::SESSION => $answer]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->opener($http)->openForAccount(SocialAccounts::bluesky());
    }
}
